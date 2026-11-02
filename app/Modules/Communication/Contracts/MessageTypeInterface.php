<?php

namespace App\Modules\Communication\Contracts;

interface MessageTypeInterface
{
    /**
     * Validate the message data
     */
    public function validate(array $data): bool;

    /**
     * Process the message data
     */
    public function process(array $data): array;
}
