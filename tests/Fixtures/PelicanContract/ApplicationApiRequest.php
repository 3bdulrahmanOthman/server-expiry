<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Application;

use App\Services\Acl\Api\AdminAcl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Declaration-only contract snapshot of Pelican's Application API request base
 * class, used by the standalone suite when a real panel checkout is unavailable.
 *
 * Source: pelican-dev/panel v1.0.0-beta38, commit e84a4afd466e32f2b376774365185fe0342b73e6
 * (app/Http/Requests/Api/Application/ApplicationApiRequest.php). Verified byte
 * identical at v1.0.0-rc.1 (46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00) and at
 * `main` on 2026-10-17, apart from a `// @phpstan-ignore` comment on the
 * `Assert::isInstanceOf($value, Model::class)` line. SHA-256 of the
 * LF-normalized beta38 file: a7eca19870e7b5a38b15727f84d2b47404a602205bf599fcfec6f3ead8d7f04e.
 *
 * Only the declarations that participate in inheritance compatibility are
 * reproduced. Method bodies are inert stubs; they are never executed. The real
 * parent class is loaded in the CI panel job and by the real-parent test when a
 * PELICAN_PANEL_PATH (or sibling `panel/`) checkout is present.
 *
 * Do not add plugin behavior here. This file is a contract, not an implementation.
 */
abstract class ApplicationApiRequest extends FormRequest
{
    /**
     * The resource that should be checked when performing the authorization
     * function for this request.
     */
    protected ?string $resource;

    /**
     * The permission level that a given API key should have for accessing
     * the defined $resource during the request cycle.
     */
    protected int $permission = AdminAcl::NONE;

    /**
     * Determine if the current user is authorized to perform the requested
     * action against the API.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string|array<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule>> */
    public function rules(): array
    {
        return [];
    }

    /**
     * Helper method allowing a developer to easily hook into this logic without
     * having to remember what the method name is called or where to use it.
     */
    public function withValidator(Validator $validator): void
    {
        // no-op
    }

    /**
     * Returns the named route parameter and asserts that it is a real model.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $expect
     */
    public function parameter(string $key, string $expect)
    {
        return null;
    }
}
