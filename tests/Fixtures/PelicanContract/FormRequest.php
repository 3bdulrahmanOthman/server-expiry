<?php

declare(strict_types=1);

namespace Illuminate\Foundation\Http;

/**
 * Declaration-only stand-in for Laravel's FormRequest.
 *
 * The contract fixture `App\Http\Requests\Api\Application\ApplicationApiRequest`
 * extends FormRequest. The standalone plugin suite has no Laravel install, so
 * this empty class provides the inheritance link the fixture parent needs.
 * No FormRequest method is overridden by the plugin's request classes, and the
 * CI panel job repeats the same check against the real Laravel class.
 */
class FormRequest {}
