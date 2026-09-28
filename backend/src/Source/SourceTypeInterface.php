<?php

namespace App\Source;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An integration feeding "source" panels (Rocket PMS, Rocket Place, Web JSON…). Add one by implementing this
 * interface: it is registered automatically (tag "cast.source_type") and listed by GET /api/source-types.
 *
 * fetch() returns a normalised payload, whose keys are documented in SourceViews (welcomeText, guest, nextArrival,
 * wifi, checkoutInfo, houseRules, localTips, items, text, title, reloadAt…). The capabilities say which panel
 * views (SourceViews::VIEWS) the payload can fill.
 */
#[AutoconfigureTag('cast.source_type')]
interface SourceTypeInterface
{
    /** Stable identifier stored with the source, e.g. "rocket_pms". */
    public function id(): string;

    public function name(): string;

    public function icon(): string;

    public function description(): string;

    /** @return list<string> views of SourceViews::VIEWS */
    public function capabilities(): array;

    /**
     * Settings asked by the administration form. "secret" fields are sealed in the database and never returned.
     *
     * @return list<array{key: string, label: string, type: 'text'|'url'|'email'|'password'|'textarea'|'select', secret?: bool, required?: bool, help?: string, placeholder?: string, options?: list<array{label: string, value: string}>}>
     */
    public function fields(): array;

    /**
     * @param array<string, mixed> $config non-secret settings as submitted
     *
     * @return array<string, mixed> the settings to store
     *
     * @throws SourceException when a setting is invalid
     */
    public function normaliseConfig(array $config): array;

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $secrets
     *
     * @return array<string, mixed> normalised payload
     *
     * @throws SourceException|\Throwable when the source cannot be read
     */
    public function fetch(array $config, #[\SensitiveParameter] array $secrets): array;
}
