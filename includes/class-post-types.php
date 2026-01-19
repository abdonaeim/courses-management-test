<?php
/**
 * Post Types Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

class CM_Post_Types {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', [$this, 'register'], 5);
    }
    
    /**
     * Register Post Types & Taxonomies
     */
    public function register() {
        // Course Post Type
        register_post_type('cm_course', [
            'labels' => [
                'name'               => __('Courses', 'courses-management'),
                'singular_name'      => __('Course', 'courses-management'),
                'add_new'            => __('Add New', 'courses-management'),
                'add_new_item'       => __('Add New Course', 'courses-management'),
                'edit_item'          => __('Edit Course', 'courses-management'),
                'new_item'           => __('New Course', 'courses-management'),
                'view_item'          => __('View Course', 'courses-management'),
                'search_items'       => __('Search Courses', 'courses-management'),
                'not_found'          => __('No courses found', 'courses-management'),
            ],
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'query_var'          => true,
            'rewrite'            => ['slug' => 'course'],
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 30,
            'supports'           => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'show_in_rest'       => true,
        ]);
        
        // Course Category
        register_taxonomy('cm_category', 'cm_course', [
            'labels' => [
                'name'          => __('Categories', 'courses-management'),
                'singular_name' => __('Category', 'courses-management'),
                'search_items'  => __('Search Categories', 'courses-management'),
                'all_items'     => __('All Categories', 'courses-management'),
                'edit_item'     => __('Edit Category', 'courses-management'),
                'add_new_item'  => __('Add New Category', 'courses-management'),
            ],
            'hierarchical' => true,
            'show_in_rest' => true,
            'rewrite'      => ['slug' => 'course-category'],
        ]);
    }
}