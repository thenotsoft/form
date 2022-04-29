<?php

declare(strict_types=1);

namespace Yiisoft\Form;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;
use Yiisoft\Form\Exception\ValueCastException;
use Yiisoft\Strings\Inflector;
use Yiisoft\Strings\StringHelper;
use Yiisoft\Validator\PostValidationHookInterface;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\RulesProviderInterface;

use function array_key_exists;
use function array_keys;
use function explode;
use function is_subclass_of;
use function property_exists;
use function str_contains;
use function strrchr;
use function substr;

/**
 * Form model represents an HTML form: its data, validation and presentation.
 */
abstract class FormModel implements FormModelInterface, PostValidationHookInterface, RulesProviderInterface
{
    private ?array $attributesMetadata = null;
    private ?array $safeAttributes = null;

    private ?FormErrorsInterface $formErrors = null;
    private ?Inflector $inflector = null;
    /** @psalm-var array<string, string|array> */
    private array $rawData = [];
    private bool $validated = false;

    public function attributes(): array
    {
        return array_keys($this->attributesMetadata());
    }

    public function safeAttributes(): array
    {
        if ($this->safeAttributes === null) {
            $this->safeAttributes = [];
            foreach ($this->getRules() as $attribute => $type) {
                [$attribute] = $this->getNestedAttribute($attribute);
                if (isset($this->attributesMetadata()[$attribute])) {
                    $this->safeAttributes[] = $attribute;
                }
            }
        }

        return $this->safeAttributes;
    }

    public function attributesMetadata(): array
    {
        if ($this->attributesMetadata === null) {
            $this->attributesMetadata = $this->collectAttributesMetadata();
        }
        return $this->attributesMetadata;
    }

    public function getAttributeHint(string $attribute): string
    {
        $attributeHints = $this->getAttributeHints();
        $hint = $attributeHints[$attribute] ?? '';
        $nestedAttributeHint = $this->getNestedAttributeValue('getAttributeHint', $attribute);

        return $nestedAttributeHint !== '' ? $nestedAttributeHint : $hint;
    }

    /**
     * @return string[]
     */
    public function getAttributeHints(): array
    {
        return [];
    }

    public function getAttributeLabel(string $attribute): string
    {
        $label = $this->generateAttributeLabel($attribute);
        $labels = $this->getAttributeLabels();

        if (array_key_exists($attribute, $labels)) {
            $label = $labels[$attribute];
        }

        $nestedAttributeLabel = $this->getNestedAttributeValue('getAttributeLabel', $attribute);

        return $nestedAttributeLabel !== '' ? $nestedAttributeLabel : $label;
    }

    /**
     * @return string[]
     */
    public function getAttributeLabels(): array
    {
        return [];
    }

    public function getAttributePlaceholder(string $attribute): string
    {
        $attributePlaceHolders = $this->getAttributePlaceholders();
        $placeholder = $attributePlaceHolders[$attribute] ?? '';
        $nestedAttributePlaceholder = $this->getNestedAttributeValue('getAttributePlaceholder', $attribute);

        return $nestedAttributePlaceholder !== '' ? $nestedAttributePlaceholder : $placeholder;
    }

    /**
     * @return string[]
     */
    public function getAttributePlaceholders(): array
    {
        return [];
    }

    public function getAttributeCastValue(string $attribute): mixed
    {
        return $this->readProperty($attribute);
    }

    public function getAttributeValue(string $attribute): mixed
    {
        return $this->rawData[$attribute] ?? $this->getAttributeCastValue($attribute);
    }

    /**
     * @return FormErrorsInterface Get FormErrors object.
     */
    public function getFormErrors(): FormErrorsInterface
    {
        if ($this->formErrors === null) {
            $this->formErrors = new FormErrors();
        }

        return $this->formErrors;
    }

    /**
     * @return string Returns classname without a namespace part or empty string when class is anonymous
     */
    public function getFormName(): string
    {
        if (str_contains(static::class, '@anonymous')) {
            return '';
        }

        $className = strrchr(static::class, '\\');
        if ($className === false) {
            return static::class;
        }

        return substr($className, 1);
    }

    public function hasAttribute(string $attribute): bool
    {
        [$attribute, $nested] = $this->getNestedAttribute($attribute);

        return $nested !== null || array_key_exists($attribute, $this->attributesMetadata());
    }

    /**
     * @psalm-param array<string, string|array> $data
     */
    public function load(array $data, ?string $formName = null): bool
    {
        $this->rawData = [];
        $scope = $formName ?? $this->getFormName();

        if ($scope === '' && !empty($data)) {
            $this->rawData = $data;
        } elseif (isset($data[$scope])) {
            /** @var array<string, string> */
            $this->rawData = $data[$scope];
        }

        foreach ($this->rawData as $name => $value) {
            try {
                $this->setAttribute($name, $value);
            } catch (ValueCastException) {
            }
        }

        return $this->rawData !== [];
    }

    public function processValidationResult(Result $result): void
    {
        foreach ($result->getErrorMessagesIndexedByAttribute() as $attribute => $errors) {
            if ($this->hasAttribute($attribute)) {
                $this->addErrors([$attribute => $errors]);
            }
        }

        $this->validated = true;
    }

    public function getRules(): array
    {
        return $this->collectNestedRules();
    }

    public function setFormErrors(FormErrorsInterface $formErrors): void
    {
        $this->formErrors = $formErrors;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * Returns the list of attribute types indexed by attribute names.
     *
     * By default, this method returns all non-static properties of the class.
     *
     * @return array list of attribute types indexed by attribute names.
     */
    protected function collectAttributesMetadata(): array
    {
        $class = new ReflectionClass($this);
        $attributes = [];

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            /** @var ReflectionNamedType|ReflectionUnionType|null $type */
            $type = $property->getType();

            $attributes[$property->getName()] = $type instanceof ReflectionNamedType ? $type->getName() : '';
        }

        return $attributes;
    }

    protected function setAttribute(string $name, mixed $value): void
    {
        [$realName] = $this->getNestedAttribute($name);

        if (isset($this->attributesMetadata()[$realName])) {
            if (in_array($realName, $this->safeAttributes(), true)) {
                $castType = $this->attributesMetadata()[$realName];
                try {
                    /** @var mixed */
                    $value = match ($castType) {
                        'bool' => (bool)$value,
                        'float' => (float)$value,
                        'int' => (int)$value,
                        'string' => (string)$value,
                        default => $value,
                    };
                } catch (Throwable $t) {
                    throw new ValueCastException(
                        sprintf(
                            'Cannot type value for property "%s" to %s, got %s.',
                            $realName,
                            $castType,
                            gettype($value)
                        )
                    );
                }
            }

            $this->writeProperty($name, $value);
        }
    }

    private function collectNestedRules(): array
    {
        $rules = [];
        foreach ($this->attributesMetadata() as $attribute => $type) {
            /** @psalm-suppress MixedMethodCall */
            $getter = static function (FormModelInterface $class, string $attribute): ?array {
                return $class->$attribute instanceof FormModelInterface ? $class->$attribute->getRules() : [];
            };

            $getter = Closure::bind($getter, null, $this);

            /** @var Closure $getter */
            foreach ($getter($this, $attribute) as $attributeName => $rule) {
                $rules[$attribute . '.' . $attributeName] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @psalm-param  non-empty-array<string, non-empty-list<string>> $items
     */
    private function addErrors(array $items): void
    {
        foreach ($items as $attribute => $errors) {
            foreach ($errors as $error) {
                $this->getFormErrors()->addError($attribute, $error);
            }
        }
    }

    private function getInflector(): Inflector
    {
        if ($this->inflector === null) {
            $this->inflector = new Inflector();
        }
        return $this->inflector;
    }

    /**
     * Generates a user-friendly attribute label based on the give attribute name.
     *
     * This is done by replacing underscores, dashes and dots with blanks and changing the first letter of each word to
     * upper case.
     *
     * For example, 'department_name' or 'DepartmentName' will generate 'Department Name'.
     *
     * @param string $name the column name.
     *
     * @return string the attribute label.
     */
    private function generateAttributeLabel(string $name): string
    {
        return StringHelper::uppercaseFirstCharacterInEachWord(
            $this->getInflector()->toWords($name)
        );
    }

    private function readProperty(string $attribute): mixed
    {
        $class = static::class;

        [$attribute, $nested] = $this->getNestedAttribute($attribute);

        if (!property_exists($class, $attribute)) {
            throw new InvalidArgumentException("Undefined property: \"$class::$attribute\".");
        }

        /** @psalm-suppress MixedMethodCall */
        $getter = static function (FormModelInterface $class, string $attribute, ?string $nested): mixed {
            return match ($nested) {
                null => $class->$attribute,
                default => $class->$attribute->getAttributeCastValue($nested),
            };
        };

        $getter = Closure::bind($getter, null, $this);

        /** @var Closure $getter */
        return $getter($this, $attribute, $nested);
    }

    private function writeProperty(string $attribute, mixed $value): void
    {
        [$attribute, $nested] = $this->getNestedAttribute($attribute);

        /** @psalm-suppress MixedMethodCall */
        $setter = static function (FormModelInterface $class, string $attribute, mixed $value, ?string $nested): void {
            if ($class->$attribute instanceof FormModelInterface) {
                $class->$attribute->load($value, '');
            } else {
                match ($nested) {
                    null => $class->$attribute = $value,
                    default => $class->$attribute->setAttribute($nested, $value),
                };
            }
        };

        $setter = Closure::bind($setter, null, $this);

        /** @var Closure $setter */
        $setter($this, $attribute, $value, $nested);
    }

    /**
     * @return string[]
     *
     * @psalm-return array{0: string, 1: null|string}
     */
    private function getNestedAttribute(string $attribute): array
    {
        if (!str_contains($attribute, '.')) {
            return [$attribute, null];
        }

        [$attribute, $nested] = explode('.', $attribute, 2);

        /** @var string */
        $attributeNested = $this->attributesMetadata()[$attribute] ?? '';

        if (!is_subclass_of($attributeNested, self::class)) {
            throw new InvalidArgumentException("Attribute \"$attribute\" is not a nested attribute.");
        }

        if (!property_exists($attributeNested, $nested)) {
            throw new InvalidArgumentException("Undefined property: \"$attributeNested::$nested\".");
        }

        return [$attribute, $nested];
    }

    private function getNestedAttributeValue(string $method, string $attribute): string
    {
        $result = '';

        [$attribute, $nested] = $this->getNestedAttribute($attribute);

        if ($nested !== null) {
            /** @var FormModelInterface $attributeNestedValue */
            $attributeNestedValue = $this->getAttributeCastValue($attribute);
            /** @var string */
            $result = $attributeNestedValue->$method($nested);
        }

        return $result;
    }

    public function isValidated(): bool
    {
        return $this->validated;
    }
}
