<?php

namespace App\Services\MessageTypes;

use App\Interfaces\MessageTypeInterface;
use InvalidArgumentException;

class MessageTypeFactory
{
    private array $availableTypes = [
        'text',
        'image',
        // Add more message types as needed
    ];

    /**
     * Create a message type handler
     */
    public function create(string $type): MessageTypeInterface
    {
        return match ($type) {
            'text' => new TextMessageType,
            'image' => new ImageMessageType,
            default => throw new InvalidArgumentException("Unsupported message type: {$type}")
        };
    }

    /**
     * Get all available message types
     */
    public function getAvailableTypes(): array
    {
        return $this->availableTypes;
    }
}
