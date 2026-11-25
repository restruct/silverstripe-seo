<?php

namespace Restruct\Silverstripe\SEO\Migration;

/**
 * Why a transform did not give a value to write, when the reason is more than "invalid" (null).
 */
enum MigrationSkip
{
    /** The value points at a file that is not published: not written to the live stage */
    case Unpublished;
}
