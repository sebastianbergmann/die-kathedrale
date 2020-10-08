#!/usr/bin/env php
<?php declare(strict_types=1);
define('ROOT_DIRECTORY', __DIR__ . DIRECTORY_SEPARATOR);
define('BUILD_DIRECTORY', ROOT_DIRECTORY . 'build' . DIRECTORY_SEPARATOR);
define('TEMPORARY_DIRECTORY', ROOT_DIRECTORY . 'tmp' . DIRECTORY_SEPARATOR);

$file = new SplFileObject(__DIR__ . '/solution.csv');
$file->setFlags(SplFileObject::READ_CSV);
$file->setCsvControl(',');

$lines         = iterator_to_array($file, true);
$movesAsArray  = [];
$movesAsString = '';
$frame         = 1;
$table         = [];
$locationWidth = 0;
$actionWidth   = 0;

foreach (range(0, count($lines) - 1) as $lineNumber) {
    $currentLine = $lines[$lineNumber];

    if (!is_array($currentLine) || !is_string($currentLine[0]) || !is_string($currentLine[1])) {
        continue;
    }

    $locationWidth = max($locationWidth, mb_strlen($currentLine[0]));
    $actionWidth   = max($actionWidth, mb_strlen($currentLine[1]));
    $table[]       = [$currentLine[0], $currentLine[1]];

    if (isset($lines[$lineNumber + 1]) && !empty($lines[$lineNumber + 1][0])) {
        $nextRoom = mapRoomNameToRoomId($lines[$lineNumber + 1][0]);
    }

    if (!empty($currentLine[0])) {
        $currentRoom = mapRoomNameToRoomId($currentLine[0]);
    }

    if (isset($currentRoom, $nextRoom)) {
        $action = $currentLine[1];

        switch ($action) {
            case 'Schiebe Sofa nach Osten':
                $action = 'Ost';
                break;

            case 'Ja':
                $action = $lines[$lineNumber - 1][1];
                break;
        }

        $move = sprintf(
            '%s -> %s [label="%s"];',
            $currentRoom,
            $nextRoom,
            $action
        );

        unset($nextRoom);

        if (isset($movesAsArray[$move])) {
            continue;
        }

        $movesAsString .= '    ' . $move . PHP_EOL;
        $movesAsArray[$move] = true;

        writeDot($frame, $movesAsString);
        eliminateUnconnectedNodes($frame);
        renderFrame($frame);
        extendRenderedFrame($frame);

        $frame++;
    }
}

convertFrameImagesToVideo($frame - 1);
renderWalkthroughMap($frame - 1);
generateMarkdown($table, $locationWidth, $actionWidth);

function mapRoomNameToRoomId(string $roomName): string
{
    return str_replace(
        [
            ' / ',
            ' ',
            'ä',
            'ö',
            'ü',
            'ß',
        ],
        [
            '_',
            '_',
            'ae',
            'oe',
            'ue',
            'ss',
        ],
        strtolower($roomName)
    );
}

function writeDot(int $frame, string $movesAsString): void
{
    file_put_contents(
        sprintf(
            '%sframe_%04d.dot',
            TEMPORARY_DIRECTORY,
            $frame
        ),
        onlyRoomsDotWithoutClosingBrace() . $movesAsString . '}'
    );
}

function onlyRoomsDotWithoutClosingBrace(): string
{
    return implode(
        '',
        array_filter(
            array_slice(
                file(__DIR__ . '/map.dot'),
                0,
                -1
            ),
            static function (string $line): bool
            {
                return strpos($line, '->') === false;
            }
        )
    );
}

function eliminateUnconnectedNodes(int $frame): void
{
    shell_exec(
        sprintf(
            'gvpr -c "N[$.degree==0]{delete(root, $)}" -o %sframe_reduced_%04d.dot %sframe_%04d.dot',
            TEMPORARY_DIRECTORY,
            $frame,
            TEMPORARY_DIRECTORY,
            $frame,
        )
    );
}

function renderFrame(int $frame): void
{
    shell_exec(
        sprintf(
            'dot -Tpng -o %sframe_%04d.png %sframe_reduced_%04d.dot',
            TEMPORARY_DIRECTORY,
            $frame,
            TEMPORARY_DIRECTORY,
            $frame,
        )
    );
}

function extendRenderedFrame(int $frame): void
{
    shell_exec(
        sprintf(
            'convert %sframe_%04d.png -gravity center -background white -extent 8000x3500 %sframe_extended_%04d.png',
            TEMPORARY_DIRECTORY,
            $frame,
            TEMPORARY_DIRECTORY,
            $frame,
        )
    );
}

function convertFrameImagesToVideo(int $numberOfFrames): void
{
    shell_exec(
        sprintf(
            'png2yuv -I p -f 4 -b 1 -n %d -j %sframe_extended_%%04d.png > %smap_walkthrough.yuv',
            $numberOfFrames,
            TEMPORARY_DIRECTORY,
            TEMPORARY_DIRECTORY,
        )
    );

    shell_exec(
        sprintf(
            'vpxenc --best --cpu-used=0 --auto-alt-ref=1 --lag-in-frames=16 --end-usage=vbr --passes=2 --threads=4 --target-bitrate=3000 -o %smap_walkthrough.webm %smap_walkthrough.yuv',
            BUILD_DIRECTORY,
            TEMPORARY_DIRECTORY,
        )
    );
}

function renderWalkthroughMap(int $frame): void
{
    shell_exec(
        sprintf(
            'neato -Tpdf -o %smap_walkthrough.pdf %sframe_reduced_%04d.dot',
            BUILD_DIRECTORY,
            TEMPORARY_DIRECTORY,
            $frame
        )
    );

    shell_exec(
        sprintf(
            'neato -Tpng -o %smap_walkthrough.png %sframe_reduced_%04d.dot',
            BUILD_DIRECTORY,
            TEMPORARY_DIRECTORY,
            $frame
        )
    );

    shell_exec(
        sprintf(
            'neato -Tsvg -o %smap_walkthrough.svg %sframe_reduced_%04d.dot',
            BUILD_DIRECTORY,
            TEMPORARY_DIRECTORY,
            $frame
        )
    );

    shell_exec(
        sprintf(
            'dot -Tpdf -o %smap_walkthrough_dot.pdf %sframe_reduced_%04d.dot',
            BUILD_DIRECTORY,
            TEMPORARY_DIRECTORY,
            $frame
        )
    );

    shell_exec(
        sprintf(
            'dot -Tpng -o %smap_walkthrough_dot.png %sframe_reduced_%04d.dot',
            BUILD_DIRECTORY,
            TEMPORARY_DIRECTORY,
            $frame
        )
    );

    shell_exec(
        sprintf(
            'dot -Tsvg -o %smap_walkthrough_dot.svg %sframe_reduced_%04d.dot',
            BUILD_DIRECTORY,
            TEMPORARY_DIRECTORY,
            $frame
        )
    );
}

function generateMarkdown(array $table, int $locationWidth, int $actionWidth): void
{
    $buffer = 'Location                               | Action' . PHP_EOL;
    $buffer .= str_repeat('-', $locationWidth + 1) . '|' . str_repeat('-', $actionWidth + 1) . PHP_EOL;

    foreach ($table as $row) {
        $buffer .= mb_sprintf(
            '%-' . $locationWidth . 's | %s' . PHP_EOL,
            $row[0], $row[1]
        );
    }

    file_put_contents(
        BUILD_DIRECTORY . 'solution.md',
        $buffer
    );
}

function mb_sprintf($format, ...$args) {
    $params = $args;

    return sprintf(
        preg_replace_callback(
            '/(?<=%|%-)\d+(?=s)/',
            static function ($length) use (&$params) {
                $value = array_shift($params);

                return strlen($value) - mb_strlen($value) + $length[0];
            },
            $format
        ),
        ...$args
    );
}
