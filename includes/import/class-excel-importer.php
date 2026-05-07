<?php
namespace HCO\Import;

if ( ! defined( 'ABSPATH' ) ) exit;

class Excel_Importer {

    /* ── XLSX Parser ─────────────────────────────────────────────────── */

    public static function parse_xlsx( string $path ): array {
        $zip = new \ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            throw new \RuntimeException( 'Excel dosyası açılamadı.' );
        }
        $shared = self::shared_strings( $zip );
        $rows   = self::sheet_rows( $zip, $shared );
        $zip->close();
        return $rows;
    }

    private static function shared_strings( \ZipArchive $zip ): array {
        $out = [];
        $xml_str = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( ! $xml_str ) return $out;

        $xml = simplexml_load_string( $xml_str );
        foreach ( $xml->si as $si ) {
            if ( isset( $si->t ) ) {
                $out[] = (string) $si->t;
            } else {
                $text = '';
                foreach ( $si->r as $r ) $text .= (string) $r->t;
                $out[] = $text;
            }
        }
        return $out;
    }

    private static function sheet_rows( \ZipArchive $zip, array $shared ): array {
        $xml_str = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( ! $xml_str ) return [];

        $xml  = simplexml_load_string( $xml_str );
        $rows = [];

        foreach ( $xml->sheetData->row as $row ) {
            $cells = [];
            foreach ( $row->c as $cell ) {
                $col  = preg_replace( '/[0-9]/', '', (string) $cell['r'] );
                $idx  = self::col_index( $col );
                $type = (string) $cell['t'];
                $val  = isset( $cell->v ) ? (string) $cell->v : '';
                if ( $type === 's' ) $val = $shared[ (int) $val ] ?? '';
                $cells[ $idx ] = trim( $val );
            }
            if ( ! empty( array_filter( $cells ) ) ) {
                ksort( $cells );
                $rows[] = array_values( $cells );
            }
        }
        return $rows;
    }

    private static function col_index( string $col ): int {
        $n = 0;
        foreach ( str_split( strtoupper( $col ) ) as $ch ) {
            $n = $n * 26 + ( ord( $ch ) - 64 );
        }
        return $n - 1;
    }

    /* ── Diff: Excel vs WordPress categories ─────────────────────────── */

    public static function diff( array $rows ): array {
        // Drop header row if present
        $data = array_values( array_filter( $rows, fn( $r ) =>
            mb_strtolower( $r[0] ?? '' ) !== 'ana kategori'
        ) );

        // Build excel map: parent => [children]
        $excel = [];
        foreach ( $data as $r ) {
            $p = trim( $r[0] ?? '' );
            $c = trim( $r[1] ?? '' );
            if ( $p !== '' && $c !== '' ) $excel[ $p ][] = $c;
        }

        // Existing WP terms
        $terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] );
        $by_id   = [];
        $by_name = [];
        foreach ( $terms as $t ) {
            $by_id[ $t->term_id ]         = $t;
            $by_name[ mb_strtolower( $t->name ) ] = $t;
        }

        $result = [
            'parents'  => [],
            'children' => [],
            'summary'  => [ 'new_parents' => 0, 'existing_parents' => 0,
                            'new_children' => 0, 'exists_children'  => 0, 'moved_children' => 0 ],
        ];

        foreach ( $excel as $parent => $children ) {
            $pk     = mb_strtolower( $parent );
            $exists = isset( $by_name[ $pk ] );

            $result['parents'][ $parent ] = [
                'name'   => $parent,
                'exists' => $exists,
                'id'     => $exists ? $by_name[ $pk ]->term_id : null,
            ];

            if ( $exists ) $result['summary']['existing_parents']++;
            else           $result['summary']['new_parents']++;

            $result['children'][ $parent ] = [];

            foreach ( $children as $child ) {
                $ck = mb_strtolower( $child );

                if ( ! isset( $by_name[ $ck ] ) ) {
                    $result['children'][ $parent ][] = [
                        'name'   => $child,
                        'status' => 'new',
                    ];
                    $result['summary']['new_children']++;
                    continue;
                }

                $term          = $by_name[ $ck ];
                $current_pid   = $term->parent;
                $current_pname = isset( $by_id[ $current_pid ] ) ? $by_id[ $current_pid ]->name : null;

                if ( $current_pname !== null && mb_strtolower( $current_pname ) !== $pk ) {
                    $result['children'][ $parent ][] = [
                        'name'           => $child,
                        'status'         => 'moved',
                        'current_parent' => $current_pname,
                        'term_id'        => $term->term_id,
                    ];
                    $result['summary']['moved_children']++;
                } else {
                    $result['children'][ $parent ][] = [
                        'name'   => $child,
                        'status' => 'exists',
                    ];
                    $result['summary']['exists_children']++;
                }
            }
        }

        return $result;
    }

    /* ── Execute import ──────────────────────────────────────────────── */

    public static function execute( array $actions ): array {
        $created = 0; $moved = 0; $skipped = 0; $errors = [];

        // Collect unique parents and get/create them first
        $parent_ids = [];
        $parents_needed = array_unique( array_column( $actions, 'parent' ) );

        foreach ( $parents_needed as $name ) {
            $existing = get_term_by( 'name', $name, 'category' );
            if ( $existing ) {
                $parent_ids[ $name ] = $existing->term_id;
            } else {
                $r = wp_insert_term( $name, 'category' );
                if ( is_wp_error( $r ) ) {
                    $errors[] = "Ana kategori oluşturulamadı: {$name} — " . $r->get_error_message();
                } else {
                    $parent_ids[ $name ] = $r['term_id'];
                    $created++;
                }
            }
        }

        // Process children
        foreach ( $actions as $a ) {
            $pid = $parent_ids[ $a['parent'] ] ?? null;

            if ( $a['action'] === 'skip' ) {
                $skipped++;
                continue;
            }

            if ( ! $pid ) {
                $errors[] = "Ana kategori bulunamadı: {$a['parent']}";
                continue;
            }

            if ( $a['action'] === 'create' ) {
                $r = wp_insert_term( $a['child'], 'category', [ 'parent' => $pid ] );
                if ( is_wp_error( $r ) ) {
                    $errors[] = "Alt kategori oluşturulamadı: {$a['child']} — " . $r->get_error_message();
                } else {
                    $created++;
                }
            } elseif ( $a['action'] === 'move' ) {
                $r = wp_update_term( (int) $a['term_id'], 'category', [ 'parent' => $pid ] );
                if ( is_wp_error( $r ) ) {
                    $errors[] = "Kategori taşınamadı: {$a['child']} — " . $r->get_error_message();
                } else {
                    $moved++;
                }
            }
        }

        return compact( 'created', 'moved', 'skipped', 'errors' );
    }
}
