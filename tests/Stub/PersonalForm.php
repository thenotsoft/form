<?php

declare(strict_types=1);

namespace Yiisoft\Form\Tests\Stub;

use Yiisoft\Validator\Rule\Email;
use Yiisoft\Validator\Rule\HasLength;
use Yiisoft\Validator\Rule\MatchRegularExpression;
use Yiisoft\Validator\Rule\Required;
use Yiisoft\Form\FormModel;

final class PersonalForm extends FormModel
{
    private ?int $id = null;
    private ?string $email = null;
    private ?string $name = null;
    private ?string $имя = null;
    private ?string $password = null;
    private ?string $address = null;
    private object $cities;
    private int $cityBirth = 0;
    private array $citiesVisited = [];
    private ?string $entryDate = null;
    private ?int $sex = null;
    private bool $terms = false;
    private ?string $attachFiles = null;

    public function attributeLabels(): array
    {
        return [];
    }

    public function attributeHints(): array
    {
        return [
            'name' => 'Write your first name.'
        ];
    }

    public function customError(): string
    {
        return 'This is custom error message.';
    }

    public function customErrorWithIcon(): string
    {
        return '(&#10006;) This is custom error message.';
    }

    protected function rules(): array
    {
        return [
            'name' => [new Required(), (new HasLength())->min(4)->tooShortMessage('Is too short.')],
            'email' => [new Email()],
            'password' => [
                new Required(),
                (new MatchRegularExpression("/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/"))
                    ->message(
                        'Must contain at least one number and one uppercase and lowercase letter, and at least 8 or ' .
                        'more characters.'
                    )

            ]
        ];
    }

    public function address(string $value): void
    {
        $this->address = $value;
    }

    public function cities(object $value): void
    {
        $this->cities = $value;
    }

    public function citiesVisited(array $value): void
    {
        $this->citiesVisited = $value;
    }

    public function cityBirth(int $value): void
    {
        $this->cityBirth = $value;
    }

    public function email(string $value): void
    {
        $this->email = $value;
    }

    public function entryDate(string $value): void
    {
        $this->entryDate = $value;
    }

    public function name(string $value): void
    {
        $this->name = $value;
    }

    public function password(string $value): void
    {
        $this->password = $value;
    }

    public function sex(int $value): void
    {
        $this->sex = $value;
    }

    public function terms(bool $value): void
    {
        $this->terms = $value;
    }
}
