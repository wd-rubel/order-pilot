<?php
/**
 * Hook Loader
 *
 * Registers all WordPress action and filter hooks for the plugin.
 * Using a loader pattern keeps registration centralized and testable.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Loader
 *
 * Collects and runs all hook registrations for the plugin.
 */
class OP_Loader {

    /**
     * Registered actions.
     *
     * @var array
     */
    protected array $actions = [];

    /**
     * Registered filters.
     *
     * @var array
     */
    protected array $filters = [];

    /**
     * Add an action hook.
     *
     * @param string   $hook          The name of the WordPress action.
     * @param object   $component     A reference to the instance of the object.
     * @param string   $callback      The name of the function definition on the $component.
     * @param int      $priority      Optional. The priority at which the hook runs.
     * @param int      $accepted_args Optional. The number of arguments the hook accepts.
     */
    public function add_action(
        string $hook,
        object $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->actions[] = $this->build( $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * Add a filter hook.
     *
     * @param string   $hook          The name of the WordPress filter.
     * @param object   $component     A reference to the instance of the object.
     * @param string   $callback      The name of the function definition on the $component.
     * @param int      $priority      Optional. The priority at which the hook runs.
     * @param int      $accepted_args Optional. The number of arguments the hook accepts.
     */
    public function add_filter(
        string $hook,
        object $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->filters[] = $this->build( $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * Build the hook definition array.
     *
     * @param string $hook
     * @param object $component
     * @param string $callback
     * @param int    $priority
     * @param int    $accepted_args
     * @return array
     */
    private function build(
        string $hook,
        object $component,
        string $callback,
        int $priority,
        int $accepted_args
    ): array {
        return compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
    }

    /**
     * Register all actions and filters with WordPress.
     *
     * @since 1.0.0
     */
    public function run(): void {
        foreach ( $this->filters as $hook ) {
            add_filter(
                $hook['hook'],
                [ $hook['component'], $hook['callback'] ],
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        foreach ( $this->actions as $hook ) {
            add_action(
                $hook['hook'],
                [ $hook['component'], $hook['callback'] ],
                $hook['priority'],
                $hook['accepted_args']
            );
        }
    }
}
