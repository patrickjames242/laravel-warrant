<?php

namespace Warrant\LanguageServer\Protocol;

use RuntimeException;

/**
 * A request for a method the server does not answer.
 */
final class MethodNotFound extends RuntimeException
{
}
