<?php

namespace Warrant\LanguageServer;

/**
 * How the server learns the project's schemas, as the editor's `vocabulary`
 * setting chooses.
 */
enum VocabularyMode: string
{
    /** Read the project's source, and also ask the running application. */
    case Auto = 'auto';

    /** Read the project's source only, never running any of it. */
    case Static = 'static';

    /** Learn no schemas, and check syntax and the rules that need none. */
    case Off = 'off';
}
