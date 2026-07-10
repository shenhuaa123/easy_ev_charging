<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Session;

abstract class BaseController
{
    protected Session $session;

    protected Csrf $csrf;

    public function __construct(Session $session, Csrf $csrf)
    {
        $this->session = $session;
        $this->csrf = $csrf;
    }

    public function csrfToken(): string
    {
        return $this->csrf->token();
    }

    public function flashMessages(): array
    {
        return $this->session->getFlashMessages();
    }

    public function flashSuccess(string $message): void
    {
        $this->session->setFlash('success', $message);
    }

    public function flashError(string $message): void
    {
        $this->session->setFlash('error', $message);
    }

    public function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    public function validateCsrfOrRedirect(mixed $submittedToken, string $redirectPath): void
    {
        if(!$this->csrf->validate($submittedToken)){
            $this->flashError(Csrf::INVALID_MESSAGE);
            $this->redirect($redirectPath);
        }
    }

    public function isValidCsrf(mixed $submittedToken): bool
    {
        return $this->csrf->validate($submittedToken);
    }

    public function getPositiveIntFromInput(int $inputType, string $fieldName): ?int
    {
        $value = filter_input(
            $inputType,
            $fieldName,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        return $value === false || $value === null ? null : (int)$value;
    }
}