<?php

namespace App\Classes;

use Exception;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Spatie\Color\Factory as ColorFactory;

class FilamentInput
{
    /**
     * Evaluate a condition array against the current form state
     * Format: ['field' => 'value'] or ['field' => ['!=', 'value']] or ['field' => ['!=', 'value1', 'value2']]
     * For visible_if: Returns true when field SHOULD be visible (condition matches)
     * For disabled_if: Returns true when field SHOULD be disabled (condition matches)
     */
    private static function evaluateCondition($get, array $condition): bool
    {
        foreach ($condition as $field => $expectedValue) {
            // Try both with and without settings. prefix
            $currentValue = $get($field) ?? $get('settings.' . $field);

            if (is_array($expectedValue)) {
                // Handle operators like ['!=', 'value'], ['not_in', 'value1', 'value2'], ['==', 'value'], ['in', 'value1', 'value2']
                $operator = $expectedValue[0];
                $values = array_slice($expectedValue, 1);

                if ($operator === '!=') {
                    // Return true when current value is NOT equal to any of the expected values
                    return !in_array($currentValue, $values, true);
                }
                if ($operator === 'not_in') {
                    // Return true when current value is NOT in the list of values
                    return !in_array($currentValue, $values, true);
                }
                if ($operator === '==') {
                    // Return true when current value IS equal to one of the expected values
                    return in_array($currentValue, $values, true);
                }
                if ($operator === 'in') {
                    // Return true when current value IS in the list of values
                    return in_array($currentValue, $values, true);
                }
            } else {
                // Simple equality check - return true when equal
                return $currentValue === $expectedValue;
            }
        }

        return false;
    }

    /**
     * Convert array or object to Filament input
     *
     * @param  array|object  $setting
     * @return mixed
     */
    public static function convert($setting)
    {
        // If its already a filament component, return it
        if (is_object($setting) && method_exists($setting, 'getName')) {
            return $setting;
        }
        if (is_array($setting)) {
            $setting = (object) $setting;
        }

        switch ($setting->type) {
            case 'select':
                $select = Select::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->columnSpan($setting->column_span ?? 1)
                    ->preload() // Always preload selected values
                    ->searchable($setting->searchable ?? false) // Enable search for large lists
                    ->multiple($setting->multiple ?? false)
                    ->required($setting->required ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->placeholder($setting->placeholder ?? 'Select an option')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->hintActions(isset($setting->action) ? [($setting->action)::make()] : [])
                    ->rules($setting->validation ?? []);

                // Handle dynamic IP address loading based on selected pool
                if (isset($setting->loadIpAddresses) && $setting->loadIpAddresses) {
                    // Store the address field type for the closure
                    $fieldName = str_replace('settings.', '', $setting->name);

                    // Map address field to pool field
                    $poolFieldMap = [
                        'ipv4_address' => 'settings.ipv4_pool_id',
                        'ipv4_private_address' => 'settings.ipv4_private_pool_id',
                        'ipv6_address' => 'settings.ipv6_pool_id',
                    ];

                    $poolField = $poolFieldMap[$fieldName] ?? null;

                    if ($poolField) {
                        // Load options dynamically based on pool selection
                        $select->options(function ($get, $component) use ($poolField) {
                            $poolId = $get($poolField);

                            if (empty($poolId) || $poolId === 'auto' || $poolId === 'disabled') {
                                return ['auto' => 'Auto-select from pool'];
                            }

                            if (!is_numeric($poolId)) {
                                return ['auto' => 'Auto-select from pool'];
                            }

                            // Cache IP addresses to reduce database queries
                            $cacheKey = "available_ips_{$poolId}";
                            $ips = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($poolId) {
                                return \App\Models\IpAddress::where('ip_pool_id', (int)$poolId)
                                    ->where('is_assigned', false)
                                    ->pluck('ip_address', 'ip_address')
                                    ->toArray();
                            });

                            if (empty($ips)) {
                                return ['auto' => 'Auto-select from pool', 'no_ips' => 'No IPs available in this pool'];
                            }

                            return ['auto' => 'Auto-select from pool'] + $ips;
                        })
                        ->getOptionLabelUsing(function ($value) {
                            if ($value === 'auto') return 'Auto-select from pool';
                            if ($value === 'no_ips') return 'No IPs available';
                            return $value;
                        })
                        ->getSearchResultsUsing(function ($search, $get, $component) use ($poolField) {
                            $poolId = $get($poolField);
                            if (empty($poolId) || $poolId === 'auto' || $poolId === 'disabled' || !is_numeric($poolId)) {
                                return ['auto' => 'Auto-select from pool'];
                            }
                            // Don't cache search results - they're too specific
                            $ips = \App\Models\IpAddress::where('ip_pool_id', (int)$poolId)
                                ->where('is_assigned', false)
                                ->where('ip_address', 'like', "%{$search}%")
                                ->pluck('ip_address', 'ip_address')
                                ->toArray();
                            return ['auto' => 'Auto-select from pool'] + $ips;
                        });
                    }
                } elseif (isset($setting->options)) {
                    // Only set static options if not using dynamic IP loading
                    $select->options(function () use ($setting) {
                        /* Possiblities:
                            1. ['value1', 'value2', 'value3']
                            2. ['value1' => 'label1', 'value2' => 'label2', 'value3' => 'label3']
                            3. [[
                                    'value' => 'value1',
                                    'label' => 'label1',
                                ], [
                                    'value' => 'value2',
                                    'label' => 'label2',
                                ]]
                        */
                        if (is_array($setting->options)) {
                            $options = [];
                            // Check if the keys are explicitly set or sequential
                            $keys = array_keys($setting->options);
                            $isSequential = $keys === range(0, count($keys) - 1);

                            foreach ($setting->options as $key => $value) {
                                // Explicitly set keys (e.g., ['key1' => 'value1', 'key2' => 'value2'])
                                if (is_array($value)) {
                                    $options[$value['value']] = $value['label'];
                                } else {
                                    if ($isSequential) {
                                        // Sequential keys (e.g., [0 => 'value1', 1 => 'value2'])
                                        $options[$value] = $value;
                                    } else {
                                        $options[$key] = $value;
                                    }
                                }
                            }

                            return $options;
                        } else {
                            return (array) $setting->options;
                        }
                    });
                } else {
                    $select->options([]);
                }

                // Handle visible_if condition - server-side only (no JavaScript for CSP compliance)
                if (isset($setting->visible_if)) {
                    $select->visible(fn ($get) => self::evaluateCondition($get, $setting->visible_if));
                }

                // Handle disabled_if condition - server-side only (no JavaScript for CSP compliance)
                if (isset($setting->disabled_if)) {
                    $select->disabled(fn ($get) => self::evaluateCondition($get, $setting->disabled_if));
                } elseif (isset($setting->disabled_when_strategy_not_specific) && $setting->disabled_when_strategy_not_specific) {
                    $select->disabled(fn ($get) => $get('settings.node_selection_strategy') !== 'specific');
                } elseif (isset($setting->needs_strategy_watcher) && $setting->needs_strategy_watcher) {
                    $select->disabled(fn ($get) => $get('settings.node_selection_strategy') !== 'specific');
                } else {
                    $select->disabled($setting->disabled ?? false);
                }

                return $select;
                break;

            case 'tags':
                return TagsInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->placeholder($setting->placeholder ?? '')
                    ->required($setting->required ?? false)
                    ->disabled($setting->disabled ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->rules($setting->validation ?? [])
                    ->nestedRecursiveRules($setting->nested_validation ?? [])
                    ->helperText($setting->description ?? null);
                break;

            case 'text':
                $input = TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor($setting->hintColor ?? 'primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->columnSpan($setting->column_span ?? 1)
                    ->hintActions(isset($setting->action) ? [($setting->action)::make()] : [])
                    ->rules($setting->validation ?? []);

                // Apply error state styling if needed
                if (isset($setting->state_color) && $setting->state_color === 'danger') {
                    $input = $input->hintColor('danger')->helperText($setting->error_message ?? 'This field is required');
                }

                return $input;
                break;

            case 'time':
                return TimePicker::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? null)
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? [])
                    ->seconds($setting->seconds ?? false);
                break;

            case 'textarea':
                return Textarea::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->rules($setting->validation ?? [])
                    ->disabled($setting->disabled ?? false);
                break;

            case 'markdown':
                return MarkdownEditor::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->disableAllToolbarButtons($setting->disable_toolbar ?? false)
                    ->rules($setting->validation ?? [])
                    ->disabled($setting->disabled ?? false);
                break;
            case 'password':
                return TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->password()
                    ->revealable()
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? []);
                break;
            case 'email':
                return TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->email()
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? []);
                break;
            case 'number':
                $input = TextInput::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->numeric()
                    ->minValue($setting->min_value ?? null)
                    ->maxValue($setting->max_value ?? null)
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->columnSpan($setting->column_span ?? 1)
                    ->rules($setting->validation ?? []);

                // Handle disabled_if condition - server-side only (no JavaScript for CSP compliance)
                if (isset($setting->disabled_if)) {
                    $input->disabled(fn ($get) => self::evaluateCondition($get, $setting->disabled_if));
                } else {
                    $input->disabled($setting->disabled ?? false);
                }

                return $input;

                break;
            case 'color':
                $mode = $setting->color_mode ?? 'hsl';
                $color = ColorPicker::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->placeholder($setting->placeholder ?? $setting->default ?? '')
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->live(condition: $setting->live ?? true)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->disabled($setting->disabled ?? false)
                    ->rules($setting->validation ?? [])
                    ->rules(function () {
                        return function ($attribute, $value, $fail) {
                            try {
                                ColorFactory::fromString(trim($value));
                            } catch (Exception $e) {
                                $fail('The :attribute must be a valid color.');
                            }
                        };
                    })
                    ->afterStateUpdated(function ($state, callable $set) use ($setting, $mode) {
                        try {
                            $set($setting->name, preg_replace('/,\s*/', ', ', ColorFactory::fromString(trim($state))->{'to' . ucfirst($mode)}()->__toString()));
                        } catch (Exception $e) {
                        }
                    });
                $color->$mode();

                return $color;
                break;
            case 'file':
                $input = FileUpload::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->required($setting->required ?? false)
                    ->acceptedFileTypes($setting->accept ?? [])
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->disk($setting->disk ?? 'public')
                    ->preserveFilenames($setting->preserve_filenames ?? false)
                    ->disabled($setting->disabled ?? false)
                    ->visibility($setting->visibility ?? 'public')
                    ->downloadable()
                    ->rules($setting->validation ?? []);

                if (isset($setting->file_name)) {
                    $input->getUploadedFileNameForStorageUsing(
                        fn (): string => (string) $setting->file_name,
                    );
                }

                return $input;

                break;

            case 'checkbox':
            case 'boolean':
                return Checkbox::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->required($setting->required ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? false)
                    ->disabled($setting->disabled ?? false)
                    ->columnSpan($setting->column_span ?? 1)
                    ->rules($setting->validation ?? []);
                break;

            case 'multiselect':
                $select = Select::make($setting->name)
                    ->label($setting->label ?? $setting->name)
                    ->helperText($setting->description ?? null)
                    ->columnSpan($setting->column_span ?? 1)
                    ->options(function () use ($setting) {
                        if (isset($setting->options) && is_array($setting->options)) {
                            return $setting->options;
                        }
                        return [];
                    })
                    ->multiple()
                    ->preload()
                    ->required($setting->required ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? [])
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->rules($setting->validation ?? []);

                // Handle visible_if condition
                if (isset($setting->visible_if)) {
                    $select->visible(fn ($get) => self::evaluateCondition($get, $setting->visible_if));
                }

                return $select;
                break;

            case 'placeholder':
                return Placeholder::make($setting->name)
                    ->content($setting->content ?? $setting->label ?? null)
                    ->helperText($setting->description ?? null)
                    ->hint($setting->hint ?? null)
                    ->hintColor($setting->hintColor ?? 'primary');
                break;

            case 'string':
            case 'text':
                // Use key as name if name is empty (database storage format)
                $fieldName = !empty($setting->name) ? $setting->name : $setting->key;
                return TextInput::make($fieldName)
                    ->label($setting->label ?? $setting->key ?? $fieldName)
                    ->helperText($setting->description ?? null)
                    ->required($setting->required ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->disabled($setting->disabled ?? false)
                    ->columnSpan($setting->column_span ?? 1)
                    ->rules($setting->validation ?? []);
                break;

            default:
                throw new Exception("Unknown input type: {$setting->type}");
        }
    }
}
