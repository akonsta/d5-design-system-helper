<?php
/**
 * Print Builder for Printable Documents
 *
 * Generates print-ready HTML with headers, footers, and page numbers.
 * Uses browser print (Ctrl+P / Cmd+P) - no server dependencies required.
 *
 * Usage:
 *   $builder = new PrintBuilder();
 *   $builder->display( $html_content, 'Document Title', 'Optional Subtitle' );
 *   // or
 *   $builder->display_markdown( $markdown_content, 'Title', 'Subtitle' );
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

class PrintBuilder {

	/**
	 * Plugin version for footer
	 */
	private string $version;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->version = defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '0.x.x';
	}

	/**
	 * Build a printable HTML document
	 *
	 * @param string      $content  HTML content for the body.
	 * @param string      $title    Document title (14pt, bold, centered).
	 * @param string|null $subtitle Optional subtitle (12pt, normal, centered).
	 * @return string               Complete HTML document ready for printing.
	 */
	public function build( string $content, string $title, ?string $subtitle = null ): string {
		$date = function_exists( 'wp_date' ) ? wp_date( 'j M Y' ) : gmdate( 'j M Y' );

		$subtitle_html = '';
		if ( $subtitle ) {
			$subtitle_html = '<p class="doc-subtitle">' . esc_html( $subtitle ) . '</p>';
		}

		return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html( $title ) . '</title>
    <style>
        /* Reset */
        *, *::before, *::after {
            box-sizing: border-box;
        }

        /* Base styles */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        /* Print-specific styles */
        @media print {
            body {
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            /* Page setup with footer */
            @page {
                size: A4;
                margin: 0.75in 0.75in 1in 0.75in;
            }

            /* Running footer - positioned at bottom */
            .print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                height: 30px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0 0.75in;
                font-size: 8pt;
                color: #666;
                background: white;
                border-top: 1px solid #eee;
            }

            /* Page break control */
            h1, h2, h3 {
                page-break-after: avoid;
            }

            table, pre {
                page-break-inside: avoid;
            }

            /* Page counter - works in Chrome, Safari, Firefox */
            .page-number::after {
                content: counter(page);
            }

            .page-total::after {
                content: counter(pages);
            }
        }

        @media screen {
            .print-footer {
                display: none;
            }

            .print-button-container {
                position: fixed;
                top: 20px;
                right: 20px;
                display: flex;
                gap: 10px;
                z-index: 1000;
            }

            .print-button {
                padding: 10px 20px;
                background: #2563eb;
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 14px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
            }

            .print-button:hover {
                background: #1d4ed8;
            }

            .print-button.secondary {
                background: #6b7280;
            }

            .print-button.secondary:hover {
                background: #4b5563;
            }

            /* Preview container */
            .preview-container {
                max-width: 800px;
                margin: 60px auto 40px;
                background: white;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
                padding: 40px;
            }
        }

        /* Title block */
        .title-block {
            text-align: center;
            margin-bottom: 1.5em;
            padding-bottom: 1em;
            border-bottom: 1px solid #e5e7eb;
        }

        .doc-title {
            font-size: 14pt;
            font-weight: bold;
            margin: 0 0 0.3em 0;
            color: #111;
        }

        .doc-subtitle {
            font-size: 12pt;
            font-weight: normal;
            margin: 0;
            color: #555;
        }

        /* Content styles */
        h1 { font-size: 16pt; margin-top: 1.5em; margin-bottom: 0.5em; color: #111; }
        h2 { font-size: 14pt; margin-top: 1.2em; margin-bottom: 0.4em; color: #111; }
        h3 { font-size: 12pt; margin-top: 1em; margin-bottom: 0.3em; color: #111; }
        h4 { font-size: 11pt; margin-top: 0.8em; margin-bottom: 0.3em; color: #111; }

        p { margin: 0.5em 0; }

        /* Tables */
        table {
            border-collapse: collapse;
            width: 100%;
            margin: 1em 0;
            font-size: 10pt;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
        }

        th {
            background-color: #f3f4f6;
            font-weight: 600;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        /* Code */
        code {
            font-family: "SF Mono", Menlo, Monaco, monospace;
            font-size: 9pt;
            background-color: #f3f4f6;
            padding: 2px 5px;
            border-radius: 3px;
        }

        pre {
            font-family: "SF Mono", Menlo, Monaco, monospace;
            font-size: 9pt;
            background-color: #f3f4f6;
            padding: 12px;
            border-radius: 6px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        pre code {
            background: none;
            padding: 0;
        }

        /* Lists */
        ul, ol {
            margin: 0.5em 0;
            padding-left: 1.5em;
        }

        li {
            margin: 0.2em 0;
        }

        /* Horizontal rule */
        hr {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 1.5em 0;
        }

        /* Blockquote */
        blockquote {
            margin: 1em 0;
            padding: 0.5em 1em;
            border-left: 4px solid #e5e7eb;
            color: #555;
            background: #f9fafb;
        }

        /* Links */
        a {
            color: #2563eb;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        @media print {
            a {
                color: #111;
                text-decoration: none;
            }
        }
    </style>
</head>
<body>
    <div class="print-button-container no-print">
        <button class="print-button" onclick="window.print()">Print / Save PDF</button>
        <button class="print-button secondary" onclick="window.close()">Close</button>
    </div>

    <div class="preview-container">
        <div class="title-block">
            <h1 class="doc-title">' . esc_html( $title ) . '</h1>
            ' . $subtitle_html . '
        </div>

        <div class="content">
            ' . $content . '
        </div>
    </div>

    <div class="print-footer">
        <span>Divi 5 Design System Helper v' . esc_html( $this->version ) . '</span>
        <span>' . esc_html( $date ) . ' - Page <span class="page-number"></span></span>
    </div>
</body>
</html>';
	}

	/**
	 * Build from Markdown content
	 *
	 * @param string      $markdown Markdown content.
	 * @param string      $title    Document title.
	 * @param string|null $subtitle Optional subtitle.
	 * @return string               Complete HTML document.
	 */
	public function build_from_markdown( string $markdown, string $title, ?string $subtitle = null ): string {
		$html = $this->markdown_to_html( $markdown );
		return $this->build( $html, $title, $subtitle );
	}

	/**
	 * Convert Markdown to HTML
	 *
	 * @param string $markdown Markdown content.
	 * @return string          HTML content.
	 */
	public function markdown_to_html( string $markdown ): string {
		// Use ParsedownExtra if available (it's in composer.json).
		if ( class_exists( '\\ParsedownExtra' ) ) {
			$parsedown = new \ParsedownExtra();
			$parsedown->setSafeMode( true );
			return $parsedown->text( $markdown );
		}

		// Fallback to basic Parsedown.
		if ( class_exists( '\\Parsedown' ) ) {
			$parsedown = new \Parsedown();
			$parsedown->setSafeMode( true );
			return $parsedown->text( $markdown );
		}

		// Last resort: return as-is wrapped in pre.
		return '<pre>' . esc_html( $markdown ) . '</pre>';
	}

	/**
	 * Output as downloadable HTML file
	 *
	 * @param string      $content  HTML or Markdown content.
	 * @param string      $title    Document title.
	 * @param string|null $subtitle Optional subtitle.
	 * @param string      $filename Output filename (without extension).
	 * @param bool        $markdown Whether content is Markdown (default: false).
	 */
	public function download( string $content, string $title, ?string $subtitle = null, string $filename = 'document', bool $markdown = false ): void {
		if ( $markdown ) {
			$html = $this->build_from_markdown( $content, $title, $subtitle );
		} else {
			$html = $this->build( $content, $title, $subtitle );
		}

		// Sanitize filename.
		$filename = sanitize_file_name( $filename ) . '.html';

		// Send headers.
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $html ) );

		echo $html;
		exit;
	}

	/**
	 * Display in browser (for print preview)
	 *
	 * @param string      $content  HTML content.
	 * @param string      $title    Document title.
	 * @param string|null $subtitle Optional subtitle.
	 */
	public function display( string $content, string $title, ?string $subtitle = null ): void {
		echo $this->build( $content, $title, $subtitle );
		exit;
	}

	/**
	 * Display Markdown content in browser
	 *
	 * @param string      $markdown Markdown content.
	 * @param string      $title    Document title.
	 * @param string|null $subtitle Optional subtitle.
	 */
	public function display_markdown( string $markdown, string $title, ?string $subtitle = null ): void {
		echo $this->build_from_markdown( $markdown, $title, $subtitle );
		exit;
	}
}
