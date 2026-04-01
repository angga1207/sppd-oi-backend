<?php

namespace App\Traits;

trait HasNanoId
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    protected static function bootHasNanoId(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = static::generateNanoId();
            }
        });
    }

    public static function generateNanoId(int $size = 11): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
        $id = '';
        for ($i = 0; $i < $size; $i++) {
            $id .= $alphabet[random_int(0, 63)];
        }
        return $id;
    }
}
