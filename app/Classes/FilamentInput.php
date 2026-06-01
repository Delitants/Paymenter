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
     * Format: ['field' => 'value'] or ['field' => ['!=', 'value']]
     * For visible_if: Returns true when field SHOULD be visible (condition matches)
     * For disabled_if: Returns true when field SHOULD be disabled (condition matches)
     */
    private static function evaluateCondition($get, array $condition): bool
    {
        foreach ($condition as $field => $expectedValue) {
            // Try both with and without settings. prefix
            $currentValue = $get($field) ?? $get('settings.' . $field);

            if (is_array($expectedValue)) {
                // Handle operators like ['!=', 'value']
                $operator = $expectedValue[0];
                $value = $expectedValue[1];

                if ($operator === '!=') {
                    // Return true when current value is NOT equal to expected
                    return $currentValue !== $value;
                }
                if ($operator === '==') {
                    // Return true when current value IS equal to expected
                    return $currentValue === $value;
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
                    ->options(function () use ($setting) {
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
                        if (isset($setting->options)) {
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
                        }

                        return [];
                    })
                    ->preload()
                    ->multiple($setting->multiple ?? false)
                    ->required($setting->required ?? false)
                    ->hint($setting->hint ?? null)
                    ->hintColor('primary')
                    ->live(condition: $setting->live ?? false)
                    ->default($setting->default ?? '')
                    ->suffix($setting->suffix ?? null)
                    ->prefix($setting->prefix ?? null)
                    ->hintActions(isset($setting->action) ? [($setting->action)::make()] : [])
                    ->rules($setting->validation ?? []);

                // Handle visible_if condition
                if (isset($setting->visible_if)) {
                    $select->visible(fn ($get) => self::evaluateCondition($get, $setting->visible_if));
                }

                // Handle disabled_if condition - use JavaScript for client-side reactivity
                if (isset($setting->disabled_if) || (isset($setting->disabled_when_strategy_not_specific) && $setting->disabled_when_strategy_not_specific)) {
                    // First set disabled state
                    $select->disabled(false);

                    // Build the JavaScript condition for disabling
                    if (isset($setting->disabled_if)) {
                        // Convert PHP condition to JS
                        $condition = $setting->disabled_if;
                        $jsConditions = [];
                        foreach ($condition as $field => $expectedValue) {
                            if (is_array($expectedValue) && $expectedValue[0] === '!=') {
                                $jsConditions[] = "get('{$field}') !== '{$expectedValue[1]}'";
                            } elseif (is_array($expectedValue) && $expectedValue[0] === '==') {
                                $jsConditions[] = "get('{$field}') === '{$expectedValue[1]}'";
                            } else {
                                $jsConditions[] = "get('{$field}') === '{$expectedValue}'";
                            }
                        }
                        $jsCondition = implode(' && ', $jsConditions);
                    } else {
                        // disabled_when_strategy_not_specific - use full field path
                        $jsCondition = "get('settings.node_selection_strategy') !== 'specific'";
                    }

                    $select->disabledJs($jsCondition);
                }
                // Handle needs_strategy_watcher - add JavaScript watcher
                elseif (isset($setting->needs_strategy_watcher) && $setting->needs_strategy_watcher) {
                    $select->disabledJs("get('settings.node_selection_strategy') !== 'specific'");
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
                return TextInput::make($setting->name)
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
                    ->disabled($setting->disabled ?? false)
                    ->columnSpan($setting->column_span ?? 1)
                    ->rules($setting->validation ?? []);

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

            default:
                throw new Exception("Unknown input type: {$setting->type}");
        }
    }
}
