<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DependencyInjection;

use App\Constants;
use App\Entity\Customer;
use App\Entity\User;
use App\Repository\InvoiceDocumentRepository;
use App\Timesheet\Rounding\RoundingInterface;
use App\Widget\Type\CompoundRow;
use App\Widget\Type\Counter;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This class validates and merges configuration from the files:
 * - config/packages/kimai.yaml
 * - config/packages/local.yaml
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('kimai');
        /** @var ArrayNodeDefinition $node */
        $node = $treeBuilder->getRootNode();

        $node
            ->children()
                ->scalarNode('data_dir')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(function ($value) {
                            if (null === $value) {
                                return false;
                            }

                            return !file_exists($value);
                        })
                        ->thenInvalid('Data directory does not exist')
                    ->end()
                ->end()
                ->scalarNode('plugin_dir')
                    ->setDeprecated('Changing the plugin directory via "kimai.plugin_dir" is not supported since 1.9')
                ->end()
                ->append($this->getUserNode())
                ->append($this->getTimesheetNode())
                ->append($this->getInvoiceNode())
                ->append($this->getExportNode())
                ->append($this->getLanguagesNode())
                ->append($this->getCalendarNode())
                ->append($this->getThemeNode())
                ->append($this->getCompanyNode())
                ->append($this->getIndustryNode())
                ->append($this->getDashboardNode())
                ->append($this->getWidgetsNode())
                ->append($this->getDefaultsNode())
                ->append($this->getPermissionsNode())
                ->append($this->getLdapNode())
                ->append($this->getSamlNode())
                ->append($this->getQuickEntryNode())
            ->end()
        ->end();

        return $treeBuilder;
    }

    private function getQuickEntryNode()
    {
        $builder = new TreeBuilder('quick_entry');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('recent_activities')
                    ->defaultValue(5)
                ->end()
                ->integerNode('recent_activity_weeks')
                    ->defaultNull()
                ->end()
                ->integerNode('minimum_rows')
                    ->defaultValue(3)
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getTimesheetNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('timesheet');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->children()
                ->scalarNode('default_begin')
                    ->defaultValue('now')
                ->end()
                ->booleanNode('duration_only')
                    ->setDeprecated()
                ->end()
                ->scalarNode('mode')
                    ->defaultValue('default')
                ->end()
                ->booleanNode('markdown_content')
                    ->defaultValue(false)
                ->end()
                ->scalarNode('duration_increment')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(function ($value) {
                            if ($value !== null) {
                                return ((int) $value) < 0;
                            }

                            return false;
                        })
                        ->thenInvalid('Duration increment is invalid')
                    ->end()
                ->end()
                ->scalarNode('time_increment')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(function ($value) {
                            if ($value !== null) {
                                return ((int) $value) < 1;
                            }

                            return false;
                        })
                        ->thenInvalid('Time increment is invalid')
                    ->end()
                ->end()
                ->arrayNode('rounding')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('days')
                                ->useAttributeAsKey('key')
                                ->scalarPrototype()->end()
                                ->defaultValue(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                            ->end()
                            ->integerNode('begin')
                                ->defaultValue(1)
                            ->end()
                            ->integerNode('end')
                                ->defaultValue(1)
                            ->end()
                            ->integerNode('duration')
                                ->defaultValue(0)
                            ->end()
                            ->scalarNode('mode')
                                ->defaultValue('default')
                                ->validate()
                                    ->ifTrue(function ($value) {
                                        $class = 'App\\Timesheet\\Rounding\\' . ucfirst($value) . 'Rounding';
                                        if (class_exists($class)) {
                                            $rounding = new $class();

                                            return !($rounding instanceof RoundingInterface);
                                        }

                                        return false;
                                    })
                                    ->thenInvalid('Chosen rounding mode is invalid')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([
                        'default' => [
                            'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                            'begin' => 1,
                            'end' => 1,
                            'duration' => 0,
                            'mode' => 'default'
                        ]
                    ])
                ->end()
                ->arrayNode('rates')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('days')
                                ->requiresAtLeastOneElement()
                                ->useAttributeAsKey('key')
                                ->isRequired()
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                            ->end()
                            ->floatNode('factor')
                                ->isRequired()
                                ->defaultValue(1)
                                ->validate()
                                    ->ifTrue(function ($value) {
                                        return $value <= 0;
                                    })
                                    ->thenInvalid('A rate factor smaller or equals 0 is not allowed')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('active_entries')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('soft_limit')
                            ->defaultValue(1)
                            ->setDeprecated('The node "%node%" at path "%path%" is deprecated, please use "kimai.timesheet.active_entries.hard_limit" instead.')
                            ->validate()
                                ->ifTrue(function ($value) {
                                    return $value <= 0;
                                })
                                ->thenInvalid('The soft_limit must be at least 1')
                            ->end()
                        ->end()
                        ->integerNode('hard_limit')
                            ->defaultValue(1)
                            ->validate()
                                ->ifTrue(function ($value) {
                                    return $value <= 0;
                                })
                                ->thenInvalid('The hard_limit must be at least 1')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('rules')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('allow_future_times')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('allow_zero_duration')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('allow_overbooking_budget')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('allow_overlapping_records')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('lockdown_period_start')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('lockdown_period_end')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('lockdown_period_timezone')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('lockdown_grace_period')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('lockdown_grace_period')
                            ->defaultNull()
                        ->end()
                        ->integerNode('break_warning_duration')
                            ->defaultValue(0)
                        ->end()
                        ->integerNode('long_running_duration')
                            ->defaultValue(0)
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getInvoiceNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('invoice');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('defaults')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'var/invoices/',
                        InvoiceDocumentRepository::DEFAULT_DIRECTORY
                    ])
                ->end()
                ->arrayNode('documents')
                    ->requiresAtLeastOneElement()
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('simple_form')
                    ->defaultFalse()
                ->end()
                ->scalarNode('number_format')
                    ->defaultValue('{Y}/{cy,3}')
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getExportNode()
    {
        $builder = new TreeBuilder('export');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('defaults')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'var/export/',
                        'templates/export/renderer/'
                    ])
                ->end()
                ->arrayNode('documents')
                    ->requiresAtLeastOneElement()
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getLanguagesNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('languages');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->useAttributeAsKey('name', false) // see https://github.com/symfony/symfony/issues/18988
            ->arrayPrototype()
                ->children()
                    ->scalarNode('date_time_type')                                              // for DateTimeType
                        ->defaultValue('yyyy-MM-dd HH:mm')
                        ->setDeprecated('date_time_type is deprecated since 1.16 and was replaced by the 24 user configuration')
                    ->end()
                    ->scalarNode('date_type')->defaultValue('yyyy-MM-dd')->end()                // for DateType
                    ->scalarNode('date')->defaultValue('Y-m-d')->end()                          // for display via twig
                    ->scalarNode('date_time')->defaultValue('m-d H:i')->end()                   // for display via twig
                    ->scalarNode('duration')->defaultValue('%%h:%%m h')->end()                  // for display via twig
                    ->scalarNode('time')->defaultValue('H:i')->end()                            // for display via twig
                    ->booleanNode('24_hours')                                                   // for DateTimeType JS component
                        ->defaultTrue()
                        ->setDeprecated('24_hours is deprecated since 1.16 and a user configuration now')
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getCalendarNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('calendar');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('week_numbers')->defaultTrue()->end()
                ->integerNode('day_limit')->defaultValue(4)->end()
                ->scalarNode('slot_duration')->defaultValue('00:30:00')->end()
                ->arrayNode('businessHours')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('days')
                            ->requiresAtLeastOneElement()
                            ->integerPrototype()->end()
                            ->defaultValue([1, 2, 3, 4, 5])
                        ->end()
                        ->scalarNode('begin')->defaultValue('08:00')->end()
                        ->scalarNode('end')->defaultValue('20:00')->end()
                    ->end()
                ->end()
                ->arrayNode('visibleHours')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('begin')->defaultValue('00:00')->end()
                        ->scalarNode('end')->defaultValue('23:59')->end()
                    ->end()
                ->end()
                ->arrayNode('google')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('api_key')->defaultNull()->end()
                        ->arrayNode('sources')
                            ->requiresAtLeastOneElement()
                            ->useAttributeAsKey('key')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('id')->isRequired()->end()
                                    ->scalarNode('color')->defaultValue('#ccc')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('weekends')->defaultTrue()->end()
                ->integerNode('dragdrop_amount')
                    ->defaultValue(10)
                    ->validate()
                        ->ifTrue(static function ($v) {
                            if ($v === null || $v < 0 || $v > 20) {
                                return true;
                            }

                            return false;
                        })
                        ->thenInvalid('The dragdrop_amount must be between 0 and 20')
                    ->end()
                ->end()
                ->enumNode('title_pattern')
                    ->values(['{activity}', '{project}', '{customer}', '{description}'])
                    ->defaultValue('{activity}')
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getThemeNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('theme');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('active_warning')
                    ->defaultValue(3)
                    ->setDeprecated('The node "%node%" at path "%path%" is deprecated, please use "kimai.timesheet.active_entries.soft_limit" instead.')
                ->end()
                ->scalarNode('box_color')
                    ->defaultValue('blue')
                    ->setDeprecated('The node "%node%" at path "%path%" was removed, please delete it from your config.')
                ->end()
                ->scalarNode('select_type')
                    ->defaultValue('selectpicker')
                    ->setDeprecated()
                ->end()
                ->booleanNode('tags_create')
                    ->defaultTrue()
                ->end()
                ->booleanNode('show_about')
                    ->defaultTrue()
                ->end()
                ->booleanNode('colors_limited')
                    ->defaultTrue()
                ->end()
                ->scalarNode('color_choices')
                    ->defaultValue(implode(',', [
                        'Silver|#c0c0c0', 'Gray|#808080', 'Black|#000000',
                        'Maroon|#800000', 'Brown|#a52a2a', 'Red|#ff0000', 'Orange|#ffa500',
                        'Gold|#ffd700', 'Yellow|#ffff00', 'Peach|#ffdab9', 'Khaki|#f0e68c',
                        'Olive|#808000', 'Lime|#00ff00', 'Jelly|#9acd32', 'Green|#008000', 'Teal|#008080',
                        'Aqua|#00ffff', 'LightBlue|#add8e6', 'DeepSky|#00bfff', 'Dodger|#1e90ff', 'Blue|#0000ff', 'Navy|#000080',
                        'Purple|#800080', 'Fuchsia|#ff00ff', 'Violet|#ee82ee', 'Rose|#ffe4e1', 'Lavender|#E6E6FA'
                    ]))
                ->end()
                ->arrayNode('chart')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('background_color')->defaultValue('#3c8dbc')->end() // rgba(0,115,183,0.7) = #0073b7 = Constants::DEFAULT_COLOR
                        ->scalarNode('border_color')->defaultValue('#3b8bba')->end()
                        ->scalarNode('grid_color')->defaultValue('rgba(0,0,0,.05)')->end()
                        ->scalarNode('height')->defaultValue('200')->end()
                    ->end()
                ->end()
                ->arrayNode('calendar')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('background_color')->defaultValue(Constants::DEFAULT_COLOR)->end()
                    ->end()
                ->end()
                ->arrayNode('branding')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('logo')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('mini')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('company')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('title')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('translation')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->integerNode('autocomplete_chars')
                    ->defaultValue(3)
                ->end()
                ->booleanNode('random_colors')
                    ->defaultTrue()
                ->end()
                ->booleanNode('avatar_url')
                    ->defaultFalse()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getIndustryNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('industry');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('translation')->defaultNull()->end()
            ->end()
        ;

        return $node;
    }

    private function getCompanyNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('company');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('financial_year')->defaultNull()->end()
            ->end()
        ;

        return $node;
    }

    private function getUserNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('user');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('login')
                    ->defaultTrue()
                ->end()
                ->booleanNode('registration')
                    ->defaultFalse()
                ->end()
                ->booleanNode('password_reset')
                    ->defaultTrue()
                ->end()
                ->integerNode('password_reset_retry_ttl')
                    ->defaultValue(7200)
                ->end()
                ->integerNode('password_reset_token_ttl')
                    ->defaultValue(86400)
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getWidgetsNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('widgets');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->requiresAtLeastOneElement()
            ->useAttributeAsKey('key')
            ->arrayPrototype()
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('title')->isRequired()->end()
                    ->scalarNode('query')->isRequired()->end()
                    ->booleanNode('user')->defaultFalse()->end()
                    ->scalarNode('begin')->end()
                    ->scalarNode('end')->end()
                    ->scalarNode('icon')->defaultValue('')->end()
                    ->scalarNode('color')->defaultValue('')->end()
                    ->scalarNode('type')->defaultValue(Counter::class)->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getDashboardNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('dashboard');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->requiresAtLeastOneElement()
                ->useAttributeAsKey('key')
                ->arrayPrototype()
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('type')->defaultValue(CompoundRow::class)->end()
                        ->integerNode('order')->defaultValue(0)->end()
                        ->scalarNode('title')->end()
                        ->scalarNode('permission')->defaultNull()->end()
                        ->arrayNode('widgets')
                            ->isRequired()
                            ->performNoDeepMerging()
                            ->scalarPrototype()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getDefaultsNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('defaults');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('customer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('timezone')->defaultNull()->end()
                        ->scalarNode('country')->defaultValue('DE')->end()
                        ->scalarNode('currency')->defaultValue(Customer::DEFAULT_CURRENCY)->end()
                    ->end()
                ->end()
                ->arrayNode('timesheet')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('billable')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('user')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('timezone')->defaultNull()->end()
                        ->scalarNode('language')->defaultValue(User::DEFAULT_LANGUAGE)->end()
                        ->scalarNode('theme')->defaultNull()->end()
                        ->scalarNode('currency')->defaultValue(Customer::DEFAULT_CURRENCY)->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getPermissionsNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('permissions');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('sets')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->useAttributeAsKey('key')
                        ->isRequired()
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                ->end()
                ->arrayNode('maps')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->useAttributeAsKey('key')
                        ->isRequired()
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                ->end()
                ->arrayNode('roles')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->isRequired()
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->defaultValue([
                        'ROLE_USER' => [],
                        'ROLE_TEAMLEAD' => [],
                        'ROLE_ADMIN' => [],
                        'ROLE_SUPER_ADMIN' => [],
                    ])
                ->end()
            ->end()
        ;

        return $node;
    }

    private function getLdapNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('ldap');
        $node = $treeBuilder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('activate')
                    ->defaultFalse()
                ->end()
                ->arrayNode('connection')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('host')->defaultNull()->end()
                        ->scalarNode('port')->defaultValue(389)->end()
                        ->scalarNode('useStartTls')->defaultFalse()->end()
                        ->scalarNode('useSsl')->defaultFalse()->end()
                        ->scalarNode('username')->end()
                        ->scalarNode('password')->end()
                        ->scalarNode('bindRequiresDn')->defaultTrue()->end()
                        ->scalarNode('baseDn')->end()
                        ->scalarNode('accountCanonicalForm')->end()
                        ->scalarNode('accountDomainName')->end()
                        ->scalarNode('accountDomainNameShort')->end()
                        ->scalarNode('accountFilterFormat')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static function ($v) {
                                    if (empty($v)) {
                                        return false;
                                    }
                                    if ($v[0] !== '(' || (substr_count($v, '(') !== substr_count($v, ')'))) {
                                        return true;
                                    }

                                    return (substr_count($v, '%s') !== 1);
                                })
                                ->thenInvalid('The accountFilterFormat must be enclosed by a matching number of parentheses "()" and contain one "%%s" replacer for the username')
                            ->end()
                        ->end()
                        ->scalarNode('allowEmptyPassword')->end()
                        ->scalarNode('optReferrals')->end()
                        ->scalarNode('tryUsernameSplit')->end()
                        ->scalarNode('networkTimeout')->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function ($v) {
                            return $v['useSsl'] && $v['useStartTls'];
                        })
                        ->thenInvalid('The ldap.connection.useSsl and ldap.connection.useStartTls options are mutually exclusive.')
                    ->end()
                ->end()
                ->arrayNode('user')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('baseDn')->defaultNull()->end()
                        ->scalarNode('filter')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static function ($v) {
                                    if (empty($v)) {
                                        return false;
                                    }
                                    if ($v[0] !== '(' || (substr_count($v, '(') !== substr_count($v, ')'))) {
                                        return true;
                                    }

                                    return (stripos($v, '%s') !== false);
                                })
                                ->thenInvalid('The ldap.user.filter must be enclosed by a matching number of parentheses "()" and must NOT contain a "%%s" replacer')
                            ->end()
                        ->end()
                        ->scalarNode('attributesFilter')->defaultValue('(objectClass=*)')->end()
                        ->scalarNode('usernameAttribute')->defaultValue('uid')->end()
                        ->arrayNode('attributes')
                            ->defaultValue([])
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('ldap_attr')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('user_method')->isRequired()->cannotBeEmpty()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('role')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('baseDn')->defaultNull()->end()
                        ->scalarNode('filter')->end()
                        ->scalarNode('usernameAttribute')->defaultValue('dn')->end()
                        ->scalarNode('nameAttribute')->defaultValue('cn')->end()
                        ->scalarNode('userDnAttribute')->defaultValue('member')->end()
                        ->arrayNode('groups')
                            ->defaultValue([])
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('ldap_value')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('role')->isRequired()->cannotBeEmpty()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static function ($v) {
                    return $v['activate'] && !\extension_loaded('ldap');
                })
                ->thenInvalid('LDAP is activated, but the LDAP PHP extension is not loaded.')
            ->end()
            ->validate()
                ->ifTrue(static function ($v) {
                    return $v['activate'] && empty($v['user']['baseDn']);
                })
                ->thenInvalid('The "ldap.user.baseDn" config must be set if LDAP is activated.')
            ->end()
        ;

        return $node;
    }

    private function getSamlNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('saml');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('activate')
                    ->defaultFalse()
                ->end()
                ->scalarNode('title')
                    ->defaultValue('Login with SAML')
                ->end()
                ->arrayNode('roles')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('resetOnLogin')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('attribute')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('mapping')
                            ->defaultValue([])
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('saml')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('kimai')->isRequired()->cannotBeEmpty()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('mapping')
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('saml')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('kimai')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('connection')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('baseurl')->end()
                        ->booleanNode('strict')->end()
                        ->booleanNode('debug')->end()
                        ->arrayNode('idp')
                            ->children()
                                ->scalarNode('entityId')->end()
                                ->scalarNode('x509cert')->end()
                                ->arrayNode('singleSignOnService')
                                    ->children()
                                        ->scalarNode('url')->end()
                                        ->scalarNode('binding')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('singleLogoutService')
                                    ->children()
                                        ->scalarNode('url')->end()
                                        ->scalarNode('binding')->end()
                                    ->end()
                                ->end()
                                ->scalarNode('certFingerprint')->end()
                                ->scalarNode('certFingerprintAlgorithm')->end()
                                ->arrayNode('x509certMulti')
                                    ->children()
                                        ->arrayNode('signing')
                                            ->prototype('scalar')->end()
                                        ->end()
                                        ->arrayNode('encryption')
                                            ->prototype('scalar')->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('sp')
                            ->children()
                                ->scalarNode('entityId')->end()
                                ->scalarNode('NameIDFormat')->end()
                                ->scalarNode('x509cert')->end()
                                ->scalarNode('privateKey')->end()
                                ->arrayNode('assertionConsumerService')
                                    ->children()
                                        ->scalarNode('url')->end()
                                        ->scalarNode('binding')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('attributeConsumingService')
                                    ->children()
                                        ->scalarNode('serviceName')->end()
                                        ->scalarNode('serviceDescription')->end()
                                        ->arrayNode('requestedAttributes')
                                            ->prototype('array')
                                                ->children()
                                                    ->scalarNode('name')->end()
                                                    ->booleanNode('isRequired')->defaultValue(false)->end()
                                                    ->scalarNode('nameFormat')->end()
                                                    ->scalarNode('friendlyName')->end()
                                                    ->arrayNode('attributeValue')->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                                ->arrayNode('singleLogoutService')
                                    ->children()
                                        ->scalarNode('url')->end()
                                        ->scalarNode('binding')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('security')
                            ->children()
                                ->booleanNode('nameIdEncrypted')->end()
                                ->booleanNode('authnRequestsSigned')->end()
                                ->booleanNode('logoutRequestSigned')->end()
                                ->booleanNode('logoutResponseSigned')->end()
                                ->booleanNode('wantMessagesSigned')->end()
                                ->booleanNode('wantAssertionsSigned')->end()
                                ->booleanNode('wantAssertionsEncrypted')->end()
                                ->booleanNode('wantNameId')->end()
                                ->booleanNode('wantNameIdEncrypted')->end()
                                ->variableNode('requestedAuthnContext')
                                    ->validate()
                                        ->ifTrue(function ($v) {
                                            return !\is_bool($v) && !\is_array($v);
                                        })
                                        ->thenInvalid('Must be an array or a bool.')
                                    ->end()
                                ->end()
                                ->booleanNode('signMetadata')->end()
                                ->booleanNode('wantXMLValidation')->end()
                                ->booleanNode('lowercaseUrlencoding')->end()
                                ->scalarNode('signatureAlgorithm')->end()
                                ->scalarNode('digestAlgorithm')->end()
                                ->scalarNode('entityManagerName')->end()
                            ->end()
                        ->end()
                        ->arrayNode('contactPerson')
                            ->children()
                                ->arrayNode('technical')
                                    ->children()
                                        ->scalarNode('givenName')->end()
                                        ->scalarNode('emailAddress')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('support')
                                    ->children()
                                        ->scalarNode('givenName')->end()
                                        ->scalarNode('emailAddress')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('organization')
                            ->prototype('array')
                                ->children()
                                    ->scalarNode('name')->end()
                                    ->scalarNode('displayname')->end()
                                    ->scalarNode('url')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static function ($v) {
                    if (true !== $v['activate']) {
                        return false;
                    }
                    $found = false;
                    foreach ($v['mapping'] as $mapping) {
                        if ($mapping['kimai'] === 'email') {
                            $found = true;
                        }
                    }

                    return !$found;
                })
                ->thenInvalid('You need to configure a SAML mapping for the email attribute.')
            ->end()
        ;

        return $node;
    }
}
