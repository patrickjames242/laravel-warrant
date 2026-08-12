<?php

namespace Warrant;

class StandardAbilities
{
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const ARCHIVE = 'archive';

    public const CREATE_VIEW_UPDATE_DELETE = [
        self::CREATE,
        self::VIEW,
        self::UPDATE,
        self::DELETE,
    ];
}
