<?php

/**
 * A bare stand-in for core's WP_List_Table, which only exists inside
 * wp-admin. Just enough for a test to build one of the plugin's list tables
 * and call its own methods; nothing here renders. Required by the tests that
 * need it, not by the bootstrap.
 *
 * phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps
 * phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore, PSR2.Methods.MethodDeclaration.Underscore
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */

declare(strict_types=1);

if (! class_exists('WP_List_Table')) {
    class WP_List_Table
    {
        /** @var array<int, mixed> */
        public $items = [];

        /** @var array<string, mixed> */
        protected $_args = [];

        /** @var array<int, mixed>|null */
        protected $_column_headers;

        /**
         * @param array<string, mixed> $args
         */
        public function __construct($args = [])
        {
            $this->_args = $args;
        }

        public function get_columns()
        {
            return [];
        }

        public function prepare_items()
        {
        }

        public function no_items()
        {
        }

        public function display()
        {
        }

        public function search_box($text, $input_id)
        {
        }

        public function get_pagenum()
        {
            return 1;
        }

        public function set_pagination_args($args)
        {
        }

        protected function get_sortable_columns()
        {
            return [];
        }

        protected function column_default($item, $column_name)
        {
            return '';
        }

        protected function get_default_primary_column_name()
        {
            return (string) array_key_first($this->get_columns());
        }

        protected function get_primary_column_name()
        {
            return $this->get_default_primary_column_name();
        }

        protected function get_table_classes()
        {
            return ['widefat', 'fixed', 'striped', 'table-view-list', $this->_args['plural'] ?? ''];
        }

        protected function handle_row_actions($item, $column_name, $primary)
        {
            return $column_name === $primary ? '<button type="button" class="toggle-row"></button>' : '';
        }
    }
}
