<?php
/*
Plugin Name: AFC Fields Exporter
Description: WP CLI Tool to fetch and export all posts and acf fields from every blog into seperate json files
Version: 1.0
Author: Amber Shuey
*/

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class ResetExport {
        function __invoke() {
            $this->clean_export();
        }
        function clean_export() {
            foreach ( glob( WP_CONTENT_DIR . '/exports/blog_*_postdata.json' ) as $data_file ) {
                unlink( $data_file );
            }
        }
    }
    class ExportAllBlogs {
        private $assoc_args;
        private $blog_summary = array(
            'count' => array(),
        );
        

        function __invoke( $args, $assoc_args ) {
            $this->assoc_args = wp_parse_args(
                $assoc_args,
                array(
                    'blogs' => null,
                ),
            );
            $this->export_all_blog_posts();
        }

        function count_post( $post_type ) {
            $this->blog_summary['count']['all']        = ( $this->blog_summary['count']['all'] ?? 0 ) + 1;
            $this->blog_summary['count'][ $post_type ] = ( $this->blog_summary['count'][ $post_type ] ?? 0 ) + 1;
        }


        function export_all_blog_posts() {

            // create export directory
            if ( ! file_exists( WP_CONTENT_DIR . '/exports/' ) ) {
                WP_CLI::debug( '/exports/ directory created' );
                mkdir( WP_CONTENT_DIR . '/exports/' );
            }
            
            // get blogs
            $args = array(
                'number' => 0,
                'fields' => 'ids',
            );
            // checks for agrs passed in command
            if ( null !== $this->assoc_args['blogs'] ) {
                $includes = array();
                foreach ( explode( ',', $this->assoc_args['blogs'] ) as $blog_id ) {
                    if ( ! is_numeric( $blog_id ) ) {
                        WP_CLI::error( 'Invalid blog ID' );
                    }
                    $includes[] = $blog_id;
                }
                $args['site__in'] = $includes;
            }
            $blogs = get_sites($args);


            foreach ( $blogs as $blog ) {
                $blog_id = ( $blog instanceof WP_Site ) ? $blog->ID : $blog;
                $blog_id_debug = $blog_id;

                // establish file name based on blog ID & bail if it exists
                $file_name = 'blog_' . $blog_id . '_postdata.json';
                $file_path = WP_CONTENT_DIR . '/exports/' . $file_name;
                if ( file_exists( $file_path ) ) {
                    WP_CLI::debug( "#######     $file_name already exists. Skipping blog ID: $blog_id_debug" );
                    continue;
                }

                // switch to blog
                WP_CLI::log( '## Switching to blog ID: ' . $blog_id_debug );
                switch_to_blog( $blog_id );

                $data = array();
                $args = array(
                    'post_type' => array(
                        'post',
                        'page',
                        // custom post_types below
                        'faculty-staff',
                        'majors-minors',
                    ),
                    'post_status' => 'any',
                    'posts_per_page' => -1,
                );

                $posts = get_posts( $args );

                if ( empty($posts) ) {
                    WP_CLI::warning( 'Blog ' . $blog_id_debug . ' has no posts. Skipping.' );
                    continue;
                }
                
                
                WP_CLI::log( "fetching posts..." );
                foreach ( $posts as $post ) {
                    $post_data = $this->get_postdata( $post, $blog_id );
                    if ( ! is_array( $post_data ) || empty( $post_data ) ) {
                        WP_CLI::warning( 'Skipping invalid post ID: ' . $post->ID );
                        continue;
                    }
                    $data[] = $post_data;
                    $this->count_post( $post->post_type );
                }
                WP_CLI::debug( "POSTS FETCHED" );

                $blog_data = array(
                    'blog' => $blog_id,
                    'summary' => $this->blog_summary,
                    'post_data' => $data,
                );

                file_put_contents(
                    $file_path,
                    json_encode( $blog_data, JSON_PRETTY_PRINT )
                );

                // Validate if file was created correctly
                if ( file_exists( $file_path ) ) {
                    WP_CLI::success( "File $file_name created at $file_path" );
                } else {
                    WP_CLI::warning( "Failed to create file $file_name" );
                }

                // restore and repeat loop
                restore_current_blog();
            }

            WP_CLI::log( 'Export finished' );
        }

        function get_postdata( $post, $blog_id = null ) {
            if ( ! function_exists( 'get_fields' ) ) {
                WP_CLI::error( 'get_fields() does not exist.' );
                $fields = array();
            } else {
                $fields = get_fields( $post->ID );
            }

            $post_data = (array) $post;
            $post_data['acf_fields'] = $fields;
            return $post_data;
        }
    }

    WP_CLI::add_command( 'postexport acf', 'ExportAllBlogs' );
    WP_CLI::add_command( 'postexport purge', 'ResetExport' );
}
