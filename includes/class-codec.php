<?php
defined( 'ABSPATH' ) || exit;

/** Treat markup, attributes, URLs and application placeholders as immutable data. */
final class CTP_Codec {
    private array $tokens = array();
    private array $markup = array();
    private string $prefix;

    public function __construct() {
        $this->prefix = 'CTPX' . bin2hex( random_bytes( 6 ) ) . '_';
    }

    public function encode( string $source ): string {
        return preg_replace_callback(
            '~<[^>]*>|\[(?:/?[a-zA-Z_][^\]\r\n]*)\]|(?:https?://|mailto:|tel:)[^\s<>"\x27]+|[\w.+-]+@[\w.-]+\.[a-zA-Z]{2,}|%(?:\d+\$)?[-+0 #\x27]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]|\{\{[^{}]+\}\}|\{[\w.:-]+\}|\b1TP\d+T\b~u',
            function ( $match ) {
                $token = $this->prefix . count( $this->tokens ) . 'X';
                $this->tokens[$token] = $match[0];
                if ( str_starts_with( $match[0], '<' ) ) {
                    $this->markup[] = $token;
                }
                return $token;
            }, $source
        );
    }

    public function decode( string $translated ): string {
        if ( trim( $translated ) === '' || preg_match( '~[<>]|&lt;\s*/?\s*(?:script|iframe|style)\b~i', $translated ) ) {
            throw new RuntimeException( 'Empty translation or unexpected HTML in response.' );
        }
        foreach ( $this->tokens as $token => $value ) {
            if ( substr_count( $translated, $token ) !== 1 ) {
                throw new RuntimeException( 'A protected placeholder was changed or duplicated.' );
            }
        }
        preg_match_all( '~' . preg_quote( $this->prefix, '~' ) . '\d+X~', $translated, $matches );
        if ( array_values( array_intersect( $matches[0], $this->markup ) ) !== $this->markup ||
            array_diff( $matches[0], array_keys( $this->tokens ) ) ) {
            throw new RuntimeException( 'HTML order or protected placeholders changed.' );
        }
        return strtr( $translated, $this->tokens );
    }
}
