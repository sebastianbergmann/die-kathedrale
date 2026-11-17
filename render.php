#!/usr/bin/env php
<?php declare(strict_types=1);
define('ROOT_DIRECTORY', __DIR__ . DIRECTORY_SEPARATOR);
define('BUILD_DIRECTORY', ROOT_DIRECTORY . 'build' . DIRECTORY_SEPARATOR);
define('TEMPORARY_DIRECTORY', ROOT_DIRECTORY . 'tmp' . DIRECTORY_SEPARATOR);

// Geometry, in points: every room sits in the middle of a grid cell
const CELL_WIDTH  = 176;
const CELL_HEIGHT = 92;
const ROOM_WIDTH  = 128;
const ROOM_HEIGHT = 40;
const AREA_INSET  = 6;

// How far an exit moves on the grid; Rauf/Runter only apply within an area
const DIRECTIONS = [
    'Nord'   => [0, 1],
    'Süd'    => [0, -1],
    'Ost'    => [1, 0],
    'West'   => [-1, 0],
    'Rauf'   => [1, 1],
    'Runter' => [-1, -1],
];

const FONT             = 'Helvetica';
const COLOR_PAGE       = '#faf8f3';
const COLOR_TEXT       = '#262626';
const COLOR_MUTED      = '#6b6b6b';
const COLOR_LINE       = '#5a5a5a';
const COLOR_AREA_LINE  = '#cfcac0';
const COLOR_AREA_FILL  = '#eeeeee';
const COLOR_STAIRS     = '#a8551c';
const COLOR_FATAL      = '#b3261e';
const COLOR_EVENT      = '#3f6aa8';
const COLOR_CURRENT    = '#ffdf80';
const COLOR_CURRENT_LINE = '#b07d00';

$target = $argv[1] ?? 'all';

if (!in_array($target, ['all', 'complete', 'walkthrough'], true)) {
    fwrite(STDERR, 'Usage: render.php [complete|walkthrough]' . PHP_EOL);

    exit(1);
}

foreach ([BUILD_DIRECTORY, TEMPORARY_DIRECTORY] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory);
    }
}

try {
    $map = placeRooms(loadMap(ROOT_DIRECTORY . 'map.dot'));

    if ($target !== 'walkthrough') {
        renderMap('map_complete', toDot($map));
    }

    if ($target !== 'complete') {
        $solution = readSolution(ROOT_DIRECTORY . 'solution.csv');

        renderWalkthrough($map, $solution['moves']);
        generateMarkdown($solution['table'], $solution['locationWidth'], $solution['actionWidth']);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);

    exit(1);
}

/**
 * @return array{table: list<array{string, string}>, moves: list<array{string, string, string}>, locationWidth: int, actionWidth: int}
 */
function readSolution(string $filename): array
{
    $file = new SplFileObject($filename);
    $file->setFlags(SplFileObject::READ_CSV);
    $file->setCsvControl(',', '"', '\\');

    $lines         = iterator_to_array($file, true);
    $moves         = [];
    $seen          = [];
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

            $move = [$currentRoom, $nextRoom, $action];
            $key  = implode("\0", $move);

            unset($nextRoom);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $moves[]    = $move;
        }
    }

    return [
        'table'         => $table,
        'moves'         => $moves,
        'locationWidth' => $locationWidth,
        'actionWidth'   => $actionWidth,
    ];
}

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

/**
 * Lets Graphviz parse the map, so that this script does not have to understand the DOT language.
 *
 * @return array{rooms: array<string, array>, areas: array<string, array>, exits: list<array{from: string, to: string, label: string}>}
 */
function loadMap(string $filename): array
{
    $graph = json_decode(
        run(sprintf('dot -Tdot_json %s', escapeshellarg($filename))),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $numberOfSubgraphs = $graph['_subgraph_cnt'] ?? 0;
    $names             = [];
    $rooms             = [];
    $areas             = [];
    $exits             = [];

    foreach (array_slice($graph['objects'] ?? [], $numberOfSubgraphs) as $object) {
        $names[$object['_gvid']] = $object['name'];

        $rooms[$object['name']] = [
            'label' => $object['label'] ?? $object['name'],
            'area'  => null,
            'cell'  => isset($object['cell']) ? parseCell($object['cell'], $object['name']) : null,
            'fatal' => ($object['fatal'] ?? 'false') === 'true',
        ];
    }

    foreach (array_slice($graph['objects'] ?? [], 0, $numberOfSubgraphs) as $object) {
        if (!str_starts_with($object['name'], 'cluster_')) {
            continue;
        }

        $area = substr($object['name'], strlen('cluster_'));

        if (!isset($object['origin'])) {
            throw new RuntimeException(sprintf('map.dot: area "%s" has no origin="x,y"', $area));
        }

        $areas[$area] = [
            'title'  => $object['label'] ?? $area,
            'origin' => parseCell($object['origin'], $area),
            'fill'   => $object['fillcolor'] ?? COLOR_AREA_FILL,
        ];

        foreach ($object['nodes'] ?? [] as $id) {
            $room = $names[$id];

            if ($rooms[$room]['area'] !== null) {
                throw new RuntimeException(
                    sprintf('map.dot: room "%s" is in the areas "%s" and "%s"', $room, $rooms[$room]['area'], $area)
                );
            }

            $rooms[$room]['area'] = $area;
        }
    }

    foreach ($rooms as $room => $properties) {
        if ($properties['area'] === null) {
            throw new RuntimeException(sprintf('map.dot: room "%s" is not in an area', $room));
        }
    }

    foreach ($graph['edges'] ?? [] as $edge) {
        $from  = $names[$edge['tail']];
        $to    = $names[$edge['head']];
        $label = $edge['label'] ?? '';

        $exits[$from . "\0" . $to . "\0" . $label] = ['from' => $from, 'to' => $to, 'label' => $label];

        if (($edge['dir'] ?? '') === 'both') {
            $exits[$to . "\0" . $from . "\0" . $label] = ['from' => $to, 'to' => $from, 'label' => $label];
        }
    }

    return ['rooms' => $rooms, 'areas' => $areas, 'exits' => array_values($exits)];
}

/**
 * @return array{float, float}
 */
function parseCell(string $value, string $owner): array
{
    if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $matches)) {
        throw new RuntimeException(sprintf('map.dot: "%s" is not an "x,y" position (%s)', $value, $owner));
    }

    return [(float) $matches[1], (float) $matches[2]];
}

/**
 * Gives every room its place on the page ('x' and 'y', in cells) and every area its 'box'.
 */
function placeRooms(array $map): array
{
    foreach ($map['areas'] as $area => $properties) {
        $cells = placeArea($area, $map);

        if ($cells === []) {
            continue;
        }

        $left   = min(array_column($cells, 0));
        $bottom = min(array_column($cells, 1));
        $right  = max(array_column($cells, 0));
        $top    = max(array_column($cells, 1));

        [$x, $y] = $properties['origin'];

        foreach ($cells as $room => $cell) {
            $map['rooms'][$room]['x'] = $x + $cell[0] - $left;
            $map['rooms'][$room]['y'] = $y + $cell[1] - $bottom;
        }

        $map['areas'][$area]['box'] = [$x, $y, $x + $right - $left, $y + $top - $bottom];
    }

    $boxes = array_filter(array_column($map['areas'], 'box', 'title'));

    foreach ($boxes as $first => $a) {
        foreach ($boxes as $second => $b) {
            if ($first < $second && $a[0] < $b[2] + 1 && $b[0] < $a[2] + 1 && $a[1] < $b[3] + 1 && $b[1] < $a[3] + 1) {
                throw new RuntimeException(
                    sprintf(
                        'map.dot: the areas "%s" (%s) and "%s" (%s) overlap on the page; change an origin',
                        $first,
                        describeBox($a),
                        $second,
                        describeBox($b)
                    )
                );
            }
        }
    }

    return $map;
}

function describeBox(array $box): string
{
    return sprintf('x %g to %g, y %g to %g', $box[0], $box[2], $box[1], $box[3]);
}

/**
 * Walks the exits of an area, starting at its first room, and derives a cell for every room.
 *
 * @return array<string, array{float, float}>
 */
function placeArea(string $area, array $map): array
{
    $rooms = [];

    foreach ($map['rooms'] as $room => $properties) {
        if ($properties['area'] === $area && !$properties['fatal']) {
            $rooms[$room] = true;
        }
    }

    if ($rooms === []) {
        return [];
    }

    $labels = [];

    foreach ($map['exits'] as $exit) {
        $labels[$exit['from']][$exit['to']][] = $exit['label'];
    }

    $neighbours = [];

    foreach ($map['exits'] as $exit) {
        ['from' => $from, 'to' => $to, 'label' => $label] = $exit;

        if (!isset($rooms[$from], $rooms[$to])) {
            continue;
        }

        $offset = DIRECTIONS[$label] ?? null;

        // "Betrete Beichtstuhl" says nothing about direction, but the way back ("Nord") does
        foreach ($offset === null ? $labels[$to][$from] ?? [] : [] as $back) {
            if (isset(DIRECTIONS[$back])) {
                $offset = [-DIRECTIONS[$back][0], -DIRECTIONS[$back][1]];

                break;
            }
        }

        if ($offset !== null) {
            $neighbours[$from][] = [$to, $offset[0], $offset[1], $label];
            $neighbours[$to][]   = [$from, -$offset[0], -$offset[1], $label];
        }
    }

    $cells    = [];
    $occupied = [];
    $pinned   = [];

    foreach (array_keys($rooms) as $room) {
        if ($map['rooms'][$room]['cell'] === null) {
            continue;
        }

        $cells[$room]  = $map['rooms'][$room]['cell'];
        $pinned[$room] = true;

        occupy($occupied, $cells[$room], $room, $area);
    }

    $anchor = array_key_first($rooms);

    if (!isset($cells[$anchor])) {
        $cells[$anchor] = [0.0, 0.0];

        occupy($occupied, $cells[$anchor], $anchor, $area);
    }

    $queue    = [$anchor];
    $expanded = [];

    while ($queue !== []) {
        $room = array_shift($queue);

        if (!isset($expanded[$room])) {
            $expanded[$room] = true;

            foreach ($neighbours[$room] ?? [] as [$neighbour, $dx, $dy, $label]) {
                $cell = [$cells[$room][0] + $dx, $cells[$room][1] + $dy];

                if (!isset($cells[$neighbour])) {
                    $cells[$neighbour] = $cell;

                    occupy($occupied, $cell, $neighbour, $area);
                } elseif ($cells[$neighbour] != $cell && !isset($pinned[$room]) && !isset($pinned[$neighbour])) {
                    throw new RuntimeException(
                        sprintf(
                            'map.dot: the exits of area "%s" disagree: "%s" is at %s, but the exit "%s" between it ' .
                            'and "%s" needs it at %s; pin one of the two rooms with cell="x,y"',
                            $area,
                            $neighbour,
                            describeCell($cells[$neighbour]),
                            $label,
                            $room,
                            describeCell($cell)
                        )
                    );
                }

                $queue[] = $neighbour;
            }
        }

        // Pinned rooms that the walk has not reached place their own neighbours
        if ($queue === []) {
            $queue = array_slice(array_keys(array_diff_key($pinned, $expanded)), 0, 1);
        }
    }

    foreach (array_keys($rooms) as $room) {
        if (!isset($cells[$room])) {
            throw new RuntimeException(
                sprintf(
                    'map.dot: no exit with a direction leads to "%s" in area "%s"; pin it with cell="x,y"',
                    $room,
                    $area
                )
            );
        }
    }

    return $cells;
}

function occupy(array &$occupied, array $cell, string $room, string $area): void
{
    $key = describeCell($cell);

    if (isset($occupied[$key])) {
        throw new RuntimeException(
            sprintf(
                'map.dot: "%s" and "%s" both end up at %s in area "%s"; pin one of them with cell="x,y"',
                $occupied[$key],
                $room,
                $key,
                $area
            )
        );
    }

    $occupied[$key] = $room;
}

function describeCell(array $cell): string
{
    return sprintf('%g,%g', $cell[0], $cell[1]);
}

/**
 * Sorts the exits into what is drawn for them:
 *
 *  - exits between two rooms of an area are folded into one line
 *  - exits into another area become a note in the room they start from; a line would have to cross
 *    the plans, because the rooms with stairs are rarely at the edge of theirs
 *  - exits into fatal rooms become a list of words in the room they start from
 *
 * @return array{lines: array<string, array{from: string, to: string, forward: list<string>, backward: list<string>}>, elsewhere: array<string, list<string>>, fatal: array<string, list<string>>}
 */
function connections(array $map): array
{
    $lines     = [];
    $elsewhere = [];
    $fatal     = [];

    foreach ($map['exits'] as $exit) {
        ['from' => $from, 'to' => $to, 'label' => $label] = $exit;

        if ($map['rooms'][$to]['fatal']) {
            $fatal[$from][] = preg_replace('/^Gehe /', '', $label);

            continue;
        }

        if ($map['rooms'][$from]['fatal']) {
            continue;
        }

        if ($map['rooms'][$from]['area'] !== $map['rooms'][$to]['area']) {
            $elsewhere[$from][] = sprintf(
                '%s %s: %s',
                match ($label) {
                    'Rauf'   => '↑',
                    'Runter' => '↓',
                    ''       => '→',
                    default  => $label . ' →',
                },
                $map['areas'][$map['rooms'][$to]['area']]['title'],
                $map['rooms'][$to]['label']
            );

            continue;
        }

        $key = lineKey($from, $to);

        if (!isset($lines[$key])) {
            $lines[$key] = ['from' => $from, 'to' => $to, 'forward' => [], 'backward' => []];
        }

        $lines[$key][$lines[$key]['from'] === $from ? 'forward' : 'backward'][] = $label;
    }

    return ['lines' => $lines, 'elsewhere' => $elsewhere, 'fatal' => $fatal];
}

function lineKey(string $a, string $b): string
{
    return $a < $b ? $a . '|' . $b : $b . '|' . $a;
}

/**
 * An exit needs no label when the line it is drawn as already leads in its direction.
 */
function isImplied(string $label, float $dx, float $dy): bool
{
    return match ($label) {
        'Nord'   => $dx == 0 && $dy > 0,
        'Süd'    => $dx == 0 && $dy < 0,
        'Ost'    => $dy == 0 && $dx > 0,
        'West'   => $dy == 0 && $dx < 0,
        'Rauf'   => $dy > 0,
        'Runter' => $dy < 0,
        default  => false,
    };
}

/**
 * Describes the map, or a moment of the walkthrough, for "neato -n".
 *
 * Everything the walkthrough has not reached yet is still part of the output, but invisible: that
 * way all frames have the same size and all lines run the same way.
 *
 * @param array<string, array{from: string, to: string, forward: list<string>, backward: list<string>}>                  $events moves of the walkthrough that are not exits of the map, folded like lines
 * @param ?array{rooms: array<string, true>, lines: array<string, true>, events: array<string, true>, current: ?string} $state  what the walkthrough has reached; null shows everything
 */
function toDot(array $map, array $events = [], ?array $state = null): string
{
    ['lines' => $lines, 'elsewhere' => $elsewhere, 'fatal' => $fatal] = connections($map);

    $dot   = [];
    $dot[] = 'digraph map {';
    $dot[] = sprintf(
        '    graph [bgcolor="%s", splines=true, pad=0.4, fontname="%s", labelloc=b, labeljust=l, label=%s];',
        COLOR_PAGE,
        FONT,
        legend($events !== [])
    );
    $dot[] = sprintf(
        '    node [shape=box, style="filled,rounded", fixedsize=true, width=%.3F, fillcolor=white, color="%s", penwidth=0.8, fontname="%s", fontsize=10, fontcolor="%s"];',
        ROOM_WIDTH / 72,
        COLOR_MUTED,
        FONT,
        COLOR_TEXT
    );
    $dot[] = sprintf(
        '    edge [color="%s", penwidth=1.1, arrowsize=0.6, fontname="%s", fontsize=7.5, fontcolor="%s", labelfontname="%s", labelfontsize=7.5, labelfontcolor="%s", labeldistance=2.4, labelangle=25];',
        COLOR_LINE,
        FONT,
        COLOR_MUTED,
        FONT,
        COLOR_MUTED
    );

    foreach ($map['areas'] as $area => $properties) {
        if (!isset($properties['box'])) {
            continue;
        }

        $visible = $state === null;
        $rooms   = [];

        foreach ($map['rooms'] as $room => $roomProperties) {
            if ($roomProperties['area'] !== $area || $roomProperties['fatal']) {
                continue;
            }

            $roomIsVisible = $state === null || isset($state['rooms'][$room]);
            $visible       = $visible || $roomIsVisible;
            $name          = wrap($roomProperties['label'], 20);
            $label         = implode('<br/>', array_map('htmlspecialchars', $name));
            $notes         = 0;

            foreach ($elsewhere[$room] ?? [] as $note) {
                $note   = wrap($note, 30);
                $notes += count($note);
                $label .= smallPrint(COLOR_STAIRS, $note);
            }

            if (isset($fatal[$room])) {
                $note   = wrap('† ' . implode(' · ', $fatal[$room]), 28);
                $notes += count($note);
                $label .= smallPrint(COLOR_FATAL, $note);
            }

            $height = max(ROOM_HEIGHT, count($name) * 12 + $notes * 9.5 + 16);

            $attributes = [
                sprintf('label=<%s>', $label),
                sprintf(
                    'pos="%.1F,%.1F"',
                    ($roomProperties['x'] + 0.5) * CELL_WIDTH,
                    ($roomProperties['y'] + 0.5) * CELL_HEIGHT
                ),
                sprintf('height=%.3F', $height / 72),
            ];

            if (!$roomIsVisible) {
                $attributes[] = 'style=invis';
            } elseif ($state !== null && $state['current'] === $room) {
                $attributes[] = sprintf('fillcolor="%s", color="%s", penwidth=2', COLOR_CURRENT, COLOR_CURRENT_LINE);
            }

            $rooms[] = sprintf('        %s [%s];', $room, implode(', ', $attributes));
        }

        $dot[] = sprintf('    subgraph cluster_%s {', $area);
        $dot[] = sprintf(
            '        bb="%.1F,%.1F,%.1F,%.1F"; %s',
            $properties['box'][0] * CELL_WIDTH + AREA_INSET,
            $properties['box'][1] * CELL_HEIGHT + AREA_INSET,
            ($properties['box'][2] + 1) * CELL_WIDTH - AREA_INSET,
            ($properties['box'][3] + 1) * CELL_HEIGHT - AREA_INSET,
            $visible
                ? sprintf(
                    'label=<<b>%s</b>>; labeljust=l; labelloc=t; fontsize=11; fontcolor="%s"; style="filled,rounded"; fillcolor="%s"; color="%s";',
                    htmlspecialchars($properties['title']),
                    COLOR_MUTED,
                    $properties['fill'],
                    COLOR_AREA_LINE
                )
                : 'label=""; style=invis;'
        );

        array_push($dot, ...$rooms);

        $dot[] = '    }';
    }

    foreach ($lines as $key => $line) {
        $from = $map['rooms'][$line['from']];
        $to   = $map['rooms'][$line['to']];
        $dx   = $to['x'] - $from['x'];
        $dy   = $to['y'] - $from['y'];

        $forward  = array_filter($line['forward'], static fn (string $label): bool => $label !== '' && !isImplied($label, $dx, $dy));
        $backward = array_filter($line['backward'], static fn (string $label): bool => $label !== '' && !isImplied($label, -$dx, -$dy));

        $attributes = [$line['backward'] === [] ? 'dir=forward' : 'dir=none'];

        if (array_intersect(['Rauf', 'Runter'], [...$line['forward'], ...$line['backward']]) !== []) {
            $attributes[] = sprintf(
                'style=dashed, color="%s", fontcolor="%s", labelfontcolor="%s"',
                COLOR_STAIRS,
                COLOR_STAIRS,
                COLOR_STAIRS
            );
        }

        if ($line['backward'] === [] || array_values($forward) === array_values($backward)) {
            // One label says it all: the exit leads one way only, or has the same name both ways.
            // Graphviz puts it next to the middle of a vertical line, but across any other line,
            // which is why it goes to the start of those.
            if ($forward !== []) {
                $attributes[] = sprintf('%s="%s"', $dx == 0 ? 'label' : 'taillabel', lineLabel($forward));
            }
        } else {
            // Each exit is named at the end where it starts
            if ($forward !== []) {
                $attributes[] = sprintf('taillabel="%s"', lineLabel($forward));
            }

            if ($backward !== []) {
                $attributes[] = sprintf('headlabel="%s"', lineLabel($backward));
            }
        }

        if ($state !== null && !isset($state['lines'][$key])) {
            $attributes = ['style=invis'];
        }

        $dot[] = sprintf('    %s -> %s [%s];', $line['from'], $line['to'], implode(', ', $attributes));
    }

    foreach ($events as $key => $event) {
        $attributes = [
            $event['backward'] === [] ? 'dir=forward' : 'dir=none',
            sprintf('style=dotted, color="%s", labelfontcolor="%s"', COLOR_EVENT, COLOR_EVENT),
        ];

        foreach (['taillabel' => $event['forward'], 'headlabel' => $event['backward']] as $position => $actions) {
            if (array_filter($actions) !== []) {
                $attributes[] = sprintf('%s="%s"', $position, lineLabel(array_filter($actions)));
            }
        }

        if ($state !== null && !isset($state['events'][$key])) {
            $attributes = ['style=invis'];
        }

        $dot[] = sprintf('    %s -> %s [%s];', $event['from'], $event['to'], implode(', ', $attributes));
    }

    $dot[] = '}';

    return implode(PHP_EOL, $dot) . PHP_EOL;
}

/**
 * Labels are broken into short lines, so that they fit between two neighbouring rooms.
 *
 * @param array<string> $labels
 */
function lineLabel(array $labels): string
{
    return escape(implode('\n', array_merge(...array_map(static fn (string $label): array => wrap($label, 10), array_values($labels)))));
}

/**
 * @param list<string> $lines
 */
function smallPrint(string $color, array $lines): string
{
    return sprintf(
        '<br/><font color="%s" point-size="7.5">%s</font>',
        $color,
        implode('<br/>', array_map('htmlspecialchars', $lines))
    );
}

function legend(bool $withEvents): string
{
    $rows = [
        [COLOR_MUTED, 'Norden ist oben. Ein Befehl steht nur dort an einer Linie, wo ihre Richtung ihn nicht schon verrät.'],
        [COLOR_MUTED, '→  nur in dieser Richtung'],
        [COLOR_STAIRS, '– – –  Rauf / Runter: Rauf führt auf der Karte nach oben'],
        [COLOR_STAIRS, '↑ ↓  Rauf / Runter in einen anderen Bereich'],
        [COLOR_FATAL, '†  tödliche Worte („Gehe …“)'],
    ];

    if ($withEvents) {
        $rows[] = [COLOR_EVENT, '· · ·  Schritt der Lösung, der kein Ausgang der Karte ist'];
    }

    $legend = '';

    foreach ($rows as [$color, $text]) {
        $legend .= sprintf(
            '<tr><td align="left"><font color="%s" point-size="9">%s</font></td></tr>',
            $color,
            htmlspecialchars($text)
        );
    }

    return '<<table border="0" cellborder="0" cellspacing="0" cellpadding="1">' . $legend . '</table>>';
}

/**
 * @return list<string>
 */
function wrap(string $text, int $width): array
{
    if ($text === '') {
        return [];
    }

    $lines = [];
    $line  = '';

    foreach (explode(' ', $text) as $word) {
        if ($line !== '' && mb_strlen($line . ' ' . $word) > $width) {
            $lines[] = $line;
            $line    = $word;

            continue;
        }

        $line = $line === '' ? $word : $line . ' ' . $word;
    }

    $lines[] = $line;

    return $lines;
}

function escape(string $text): string
{
    return str_replace('"', '\"', $text);
}

function renderMap(string $name, string $dot): void
{
    $source = TEMPORARY_DIRECTORY . $name . '.dot';

    file_put_contents($source, $dot);

    foreach (['pdf', 'png', 'svg'] as $format) {
        run(
            sprintf(
                'neato -n -T%s -o %s %s',
                $format,
                escapeshellarg(BUILD_DIRECTORY . $name . '.' . $format),
                escapeshellarg($source)
            )
        );
    }
}

/**
 * @param list<array{string, string, string}> $moves
 */
function renderWalkthrough(array $map, array $moves): void
{
    $exits   = [];
    $events  = [];
    $unknown = [];

    foreach ($map['exits'] as $exit) {
        $exits[$exit['from']][$exit['to']] = true;
    }

    foreach ($moves as [$from, $to, $action]) {
        foreach ([$from, $to] as $room) {
            if (!isset($map['rooms'][$room]['x'])) {
                throw new RuntimeException(sprintf('solution.csv: "%s" is not a room of map.dot', $room));
            }
        }

        if (isset($exits[$from][$to])) {
            continue;
        }

        $unknown[] = sprintf('  %s -> %s [%s]', $from, $to, $action);

        // Like an exit into another area, a move into another area is not drawn as a line
        if ($map['rooms'][$from]['area'] !== $map['rooms'][$to]['area']) {
            continue;
        }

        $key = lineKey($from, $to);

        if (!isset($events[$key])) {
            $events[$key] = ['from' => $from, 'to' => $to, 'forward' => [], 'backward' => []];
        }

        $events[$key][$events[$key]['from'] === $from ? 'forward' : 'backward'][] = $action;
    }

    if ($unknown !== []) {
        fwrite(
            STDERR,
            'Moves of solution.csv that are not exits of map.dot (dotted lines, if both rooms are in the same area):' . PHP_EOL .
            implode(PHP_EOL, $unknown) . PHP_EOL
        );
    }

    $state = ['rooms' => [], 'lines' => [], 'events' => [], 'current' => null];
    $frame = 0;

    foreach ($moves as [$from, $to]) {
        $state['rooms'][$from] = true;
        $state['rooms'][$to]   = true;
        $state['current']      = $to;

        $state[isset($exits[$from][$to]) ? 'lines' : 'events'][lineKey($from, $to)] = true;

        $source = sprintf('%sframe_%04d.dot', TEMPORARY_DIRECTORY, ++$frame);

        file_put_contents($source, toDot($map, $events, $state));

        run(
            sprintf(
                'neato -n -Tpng -o %s %s',
                escapeshellarg(sprintf('%sframe_%04d.png', TEMPORARY_DIRECTORY, $frame)),
                escapeshellarg($source)
            )
        );
    }

    $state['current'] = null;

    renderMap('map_walkthrough', toDot($map, $events, $state));

    run(
        sprintf(
            'ffmpeg -y -loglevel error -framerate 4 -i %s -vf %s -c:v libvpx-vp9 -crf 32 -b:v 0 -pix_fmt yuv420p -row-mt 1 %s',
            escapeshellarg(TEMPORARY_DIRECTORY . 'frame_%04d.png'),
            escapeshellarg('scale=min(3840\,trunc(iw/2)*2):-2:flags=lanczos'),
            escapeshellarg(BUILD_DIRECTORY . 'map_walkthrough.webm')
        )
    );
}

/**
 * Runs a command and returns what it prints; warnings are passed on, a failure stops the build.
 */
function run(string $command): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if ($process === false) {
        throw new RuntimeException(sprintf('Cannot run "%s"', $command));
    }

    // The commands used here print to one of the two streams only, so reading them in turn cannot block
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);

    fwrite(STDERR, $errors);

    if (proc_close($process) !== 0) {
        throw new RuntimeException(sprintf('"%s" failed', $command));
    }

    return $output;
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
