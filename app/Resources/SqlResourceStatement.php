<?php

require_once __DIR__ . '/../Requests/ApiRequestException.php';

class SqlResourceStatement
{
    /** Rowset functions that reach other servers or files. */
    private const REMOTE_ROWSETS = ['OPENQUERY', 'OPENROWSET', 'OPENDATASOURCE'];
    /** `{{database:id}}`: a registered logical database, rendered from the registry. */
    private const DATABASE_PLACEHOLDER = '/^\{\{database:([a-z][a-z0-9_-]{0,63})\}\}$/D';

    private string $prefix;
    private string $body;
    private string $suffix;
    private bool $authoredPagination;
    /** The authored body, with placeholders, that source analysis reads. */
    private string $analysisBody;
    /** @var list<string> logical database ids of the placeholders, in order */
    private array $databaseIds = [];

    private function __construct(string $prefix, string $body, string $suffix, bool $authoredPagination, ?string $analysisBody = null)
    {
        $this->prefix = $prefix;
        $this->body = $body;
        $this->suffix = $suffix;
        $this->authoredPagination = $authoredPagination;
        $this->analysisBody = $analysisBody ?? $body;
    }

    public static function analyze(string $sql): self
    {
        $databaseIds = self::assertDatabaseAddressing($sql);
        $tokens = self::topLevelTokens($sql);
        if ($tokens === []) {
            throw new RuntimeException('Approved SQL resources must be read-only queries.');
        }

        $first = $tokens[0];
        if ($first['value'] === 'SELECT') {
            $bodyStart = $first['start'];
        } elseif ($first['value'] === 'WITH') {
            $bodyStart = null;
            foreach (array_slice($tokens, 1) as $token) {
                if ($token['value'] === 'SELECT') {
                    $bodyStart = $token['start'];
                    break;
                }
            }
            if ($bodyStart === null) {
                throw new RuntimeException('Approved SQL resources must be read-only queries.');
            }
        } else {
            throw new RuntimeException('Approved SQL resources must be read-only queries.');
        }

        self::scan($sql, function (string $type, int $start, string $value): void {
            if ($type === 'word' && in_array(strtoupper($value), self::REMOTE_ROWSETS, true)) {
                throw new RuntimeException('Approved SQL resources cannot read remote or ad hoc data sources.');
            }
        }, true);

        $prefix = substr($sql, 0, $bodyStart);
        $body = substr($sql, $bodyStart);
        $bodyTokens = self::topLevelTokens($body);
        foreach ($bodyTokens as $token) {
            if ($token['value'] === 'INTO') {
                throw new RuntimeException('Approved SQL resources must be read-only queries.');
            }
        }
        if (self::hasTopLevelStatementSeparator($body)) {
            throw new RuntimeException('Approved SQL resources must contain one query statement.');
        }

        $suffix = '';
        foreach ($bodyTokens as $token) {
            if ($token['value'] === 'OPTION'
                && preg_match('/^OPTION\s*\(/i', substr($body, $token['start'])) === 1) {
                $suffix = ' ' . ltrim(substr($body, $token['start']));
                $body = rtrim(substr($body, 0, $token['start']));
                $bodyTokens = self::topLevelTokens($body);
                break;
            }
        }

        $authoredPagination = false;
        foreach ($bodyTokens as $token) {
            if (in_array($token['value'], ['OFFSET', 'FETCH'], true)) {
                $authoredPagination = true;
                break;
            }
        }

        $statement = new self($prefix, $body, $suffix, $authoredPagination);
        $statement->databaseIds = $databaseIds;
        foreach ($statement->topLevelSources() as $source) {
            if ($source['server'] !== null) {
                throw new RuntimeException('Approved SQL resources cannot reference linked-server objects.');
            }
        }
        return $statement;
    }

    /** Logical database ids named by `{{database:id}}` placeholders, first use first. */
    public function databaseIds(): array
    {
        return $this->databaseIds;
    }

    /**
     * The statement to execute: each placeholder replaced, at its token
     * position, by the delimited physical name `$quotedDatabase($id)` returns.
     * Source analysis keeps reading the authored text.
     *
     * @param callable(string): string $quotedDatabase
     */
    public function withDatabases(callable $quotedDatabase): self
    {
        if ($this->databaseIds === []) return $this;
        $render = static function (string $sql) use ($quotedDatabase): string {
            $placeholders = [];
            self::scan($sql, function (string $type, int $start, string $value) use (&$placeholders): void {
                if ($type === 'placeholder') $placeholders[] = [$start, $value];
            }, true);
            foreach (array_reverse($placeholders) as [$start, $value]) {
                preg_match(self::DATABASE_PLACEHOLDER, $value, $match);
                $sql = substr($sql, 0, $start) . $quotedDatabase($match[1]) . substr($sql, $start + strlen($value));
            }
            return $sql;
        };
        $statement = new self($render($this->prefix), $render($this->body), $render($this->suffix), $this->authoredPagination, $this->analysisBody);
        $statement->databaseIds = $this->databaseIds;
        return $statement;
    }

    /**
     * Databases are addressed only through `{{database:id}}.schema.object`
     * (or `{{database:id}}..object`). Any other name of three or more parts,
     * such as `Db.dbo.Table`, `[Db].[dbo].[Table]`, `Server.Db.dbo.Table`, or a
     * three-part column reference, is rejected, as is a placeholder used in
     * any other way. Returns the placeholder ids, first use first.
     *
     * @return list<string>
     */
    private static function assertDatabaseAddressing(string $sql): array
    {
        $tokens = [];
        self::scan($sql, function (string $type, int $start, string $value) use (&$tokens): void {
            if (in_array($type, ['word', 'quoted', 'dot', 'placeholder'], true)) {
                $tokens[] = ['type' => $type, 'start' => $start, 'end' => $start + strlen($value), 'value' => $value];
            }
        }, true);
        $ids = [];
        $chain = [];
        $flush = function () use (&$chain, &$ids): void {
            $parts = [];
            $afterDot = false;
            foreach ($chain as $token) {
                if ($token['type'] !== 'dot') {
                    $parts[] = $token;
                    $afterDot = false;
                } elseif ($parts !== []) {
                    if ($afterDot) $parts[] = null;
                    $afterDot = true;
                }
            }
            $chain = [];
            foreach ($parts as $index => $part) {
                if ($part === null || $part['type'] !== 'placeholder') continue;
                if (preg_match(self::DATABASE_PLACEHOLDER, $part['value'], $match) !== 1) {
                    throw new RuntimeException('Approved SQL resources contain an invalid database placeholder.');
                }
                if ($index !== 0 || count($parts) !== 3 || $parts[2] === null) {
                    throw new RuntimeException('A database placeholder must qualify an object as {{database:id}}.schema.object.');
                }
                if (!in_array($match[1], $ids, true)) $ids[] = $match[1];
            }
            if (count($parts) >= 3 && ($parts[0] === null || $parts[0]['type'] !== 'placeholder')) {
                throw new RuntimeException('Approved SQL resources address other databases only through {{database:id}} placeholders.');
            }
        };
        $previous = null;
        foreach ($tokens as $token) {
            $adjacent = $previous !== null
                && ($previous['type'] === 'dot' || $token['type'] === 'dot')
                && trim(substr($sql, $previous['end'], $token['start'] - $previous['end'])) === '';
            if (!$adjacent) $flush();
            $chain[] = $token;
            $previous = $token;
        }
        $flush();
        return $ids;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function suffix(): string
    {
        return $this->suffix;
    }

    public function hasAuthoredPagination(): bool
    {
        return $this->authoredPagination;
    }

    /**
     * Return conservative physical-column candidates from the main SELECT's
     * top-level FROM/JOIN sources. Callers must confirm column existence via
     * database metadata and reject ambiguous matches.
     */
    public function sourceCandidates(string $column, bool $requireDirectProjection = false): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            return [];
        }
        // A main SELECT that reads one CTE has no physical source of its own;
        // candidates come from the CTE its chain leads to (`cte` names it).
        if (!$requireDirectProjection && ($cte = $this->sourceFilterCte()) !== null) {
            return $cte['statement'] === null ? [] : array_map(
                fn (array $candidate): array => ['cte' => $cte['name']] + $candidate,
                $cte['statement']->sourceCandidates($column)
            );
        }

        $tokens = self::topLevelTokens($this->analysisBody);
        $fromIndex = null;
        foreach ($tokens as $index => $token) {
            if ($token['value'] === 'FROM') {
                $fromIndex = $index;
                break;
            }
        }
        if ($fromIndex === null) {
            return [];
        }

        $projection = $requireDirectProjection
            ? $this->directProjection($column, $tokens[$fromIndex]['start'])
            : null;
        if ($requireDirectProjection && $projection === null) {
            return [];
        }
        $physicalColumn = $projection['column'] ?? $column;
        $qualifiers = $projection['qualifiers'] ?? null;
        $sources = $this->parseTopLevelSources($tokens, $fromIndex);

        if ($qualifiers !== null && $qualifiers !== []) {
            $sources = array_filter(
                $sources,
                fn (array $source): bool => in_array(strtolower($source['qualifier']), $qualifiers, true)
            );
        }

        return array_values(array_map(
            fn (array $source): array => [
                'cte' => null,
                'table' => $source['table'],
                'schema' => $source['schema'],
                'database' => $source['database'],
                'column' => $physicalColumn,
                'expression' => $source['qualifier'] . '.' . $physicalColumn,
            ],
            $sources
        ));
    }

    /**
     * The prefix with a WHERE condition placed in the CTE that source
     * candidates with a `cte` name came from.
     */
    public function prefixWithSourceFilter(?string $whereCondition): string
    {
        if ($whereCondition === null) {
            return $this->prefix;
        }
        $cte = $this->sourceFilterCte();
        if ($cte === null || $cte['statement'] === null) {
            throw new LogicException('This SQL resource has no CTE for source filters.');
        }
        return substr($this->prefix, 0, $cte['start'])
            . $cte['statement']->injectMappedFilters($whereCondition, null)
            . substr($this->prefix, $cte['end']);
    }

    /**
     * Where catalog-resolved source filters go when the main SELECT reads
     * exactly one source and it is a CTE: follow single-source CTE references
     * to the CTE that reads only physical sources, so the filter limits rows
     * before any CTE aggregates or ranks them. Null keeps them on the main
     * SELECT; a null `statement` means the chain cannot hold a filter safely:
     * a set operation in the main SELECT or a chain CTE (the filter would
     * reach one branch only), a chain CTE that anything besides the next link
     * reads (its other readers would be filtered too), or a CTE joined with
     * other sources.
     *
     * @return array{name: string, start: int, end: int, statement: ?self}|null
     */
    private function sourceFilterCte(): ?array
    {
        // Placeholders render to other lengths, so CTE offsets would not hold.
        if ($this->databaseIds !== []) return null;
        $expressions = $this->commonTableExpressions();
        $refused = ['name' => '', 'start' => 0, 'end' => 0, 'statement' => null];
        $statement = $this;
        $target = null;
        while (true) {
            $sources = $statement->topLevelSources();
            $names = array_map(fn (array $source): ?string => $source['schema'] === null
                ? strtolower($source['table']) : null, $sources);
            $referencesCte = array_filter($names, fn (?string $name): bool => $name !== null && isset($expressions[$name])) !== [];
            if (!$referencesCte) break;
            $name = $names[0];
            if (count($sources) !== 1 || ($target !== null && isset($target['visited'][$name]))) {
                return $target === null ? null : $refused;
            }
            if (self::hasTopLevelSetOperation($statement->body)
                || $this->cteReaderCount($name, $expressions) !== 1) {
                return $refused;
            }
            $range = $expressions[$name];
            $body = substr($this->prefix, $range['start'], $range['end'] - $range['start']);
            if ((self::topLevelTokens($body)[0]['value'] ?? null) !== 'SELECT' || self::hasTopLevelSetOperation($body)) {
                return $refused;
            }
            $statement = new self('', $body, '', false);
            $target = ['name' => $name, 'start' => $range['start'], 'end' => $range['end'],
                'visited' => ($target['visited'] ?? []) + [$name => true]];
        }
        if ($target === null) return null;
        unset($target['visited']);
        return $target + ['statement' => $statement];
    }

    private static function hasTopLevelSetOperation(string $sql): bool
    {
        return array_filter(self::topLevelTokens($sql), fn (array $token): bool =>
            in_array($token['value'], ['UNION', 'INTERSECT', 'EXCEPT'], true)) !== [];
    }

    /**
     * References to CTE `$name` at any depth of the main SELECT and every CTE
     * body: whole identifiers, bare or delimited. Text in strings and comments
     * never counts, and an identifier with a dot beside it is a qualified name
     * (`dbo.Name`, `Name.Column`), which a CTE never is, so it does not either.
     * An alias that repeats the name counts, so the count errs towards refusal.
     */
    private function cteReaderCount(string $name, array $expressions): int
    {
        $segments = [$this->body];
        foreach ($expressions as $range) {
            $segments[] = substr($this->prefix, $range['start'], $range['end'] - $range['start']);
        }
        $count = 0;
        foreach ($segments as $sql) {
            self::scan($sql, function (string $type, int $start, string $value) use ($sql, $name, &$count): void {
                if (!in_array($type, ['word', 'quoted'], true)) return;
                $identifier = $type === 'quoted' ? str_replace([']]', '""'], [']', '"'], substr($value, 1, -1)) : $value;
                if (strtolower($identifier) !== $name) return;
                $before = $start - 1;
                while ($before >= 0 && str_contains(" \t\n\r\f\v", $sql[$before])) $before--;
                $after = $start + strlen($value);
                while ($after < strlen($sql) && str_contains(" \t\n\r\f\v", $sql[$after])) $after++;
                if (($before >= 0 && $sql[$before] === '.') || ($after < strlen($sql) && $sql[$after] === '.')) return;
                $count++;
            }, true);
        }
        return $count;
    }

    /**
     * Body offsets, within the prefix, of each CTE it defines, by lowercase
     * name. Empty unless the prefix is exactly
     * `WITH name [(columns)] AS (body) [, name AS (body) ...]`.
     *
     * @return array<string, array{start: int, end: int}>
     */
    private function commonTableExpressions(): array
    {
        $events = [];
        self::scan($this->prefix, function (string $type, int $start, string $value) use (&$events): void {
            $events[] = [$type, $start, $value];
        });
        if (($events[0][0] ?? null) !== 'word' || strtoupper($events[0][2]) !== 'WITH') return [];
        $expressions = [];
        $expected = 'name';
        $name = null;
        $bodyStart = null;
        foreach (array_slice($events, 1) as [$type, $start, $value]) {
            if ($expected === 'name' && in_array($type, ['word', 'quoted'], true)) {
                $name = strtolower(trim($value, '[]"'));
                if (isset($expressions[$name])) return [];
                $expected = 'columns-or-as';
            } elseif ($expected === 'columns-or-as' && $type === 'open') {
                $expected = 'columns-end';
            } elseif ($expected === 'columns-end' && $type === 'close') {
                $expected = 'as';
            } elseif (in_array($expected, ['columns-or-as', 'as'], true) && $type === 'word' && strtoupper($value) === 'AS') {
                $expected = 'body';
            } elseif ($expected === 'body' && $type === 'open') {
                $bodyStart = $start + 1;
                $expected = 'body-end';
            } elseif ($expected === 'body-end' && $type === 'close') {
                $expressions[$name] = ['start' => $bodyStart, 'end' => $start];
                $expected = 'comma';
            } elseif ($expected === 'comma' && $type === 'comma') {
                $expected = 'name';
            } else {
                return [];
            }
        }
        return $expected === 'comma' ? $expressions : [];
    }

    /**
     * Physical-or-CTE sources named in the main SELECT's top-level FROM/JOIN
     * clauses: the object name (`table`), its `schema`, `database`, and
     * `server` parts when written (else null), and the effective qualifier.
     */
    public function topLevelSources(): array
    {
        $tokens = self::topLevelTokens($this->analysisBody);
        foreach ($tokens as $index => $token) {
            if ($token['value'] === 'FROM') {
                return array_values($this->parseTopLevelSources($tokens, $index));
            }
        }
        return [];
    }

    private function parseTopLevelSources(array $tokens, int $fromIndex): array
    {
        $from = $tokens[$fromIndex];
        $start = $from['start'] + strlen('FROM');
        $end = strlen($this->analysisBody);
        foreach (array_slice($tokens, $fromIndex + 1) as $token) {
            if (in_array($token['value'], ['WHERE', 'GROUP', 'HAVING', 'ORDER', 'OFFSET', 'FETCH', 'FOR'], true)) {
                $end = $token['start'];
                break;
            }
        }
        $segment = substr($this->analysisBody, $start, $end - $start);
        $starts = [0];
        self::scan($segment, function (string $type, int $position, string $value) use (&$starts): void {
            if ($type === 'comma') {
                $starts[] = $position + 1;
            } elseif ($type === 'word' && strtoupper($value) === 'JOIN') {
                $starts[] = $position + strlen($value);
            }
        });

        $sources = [];
        foreach ($starts as $position) {
            $source = $this->parseSource(substr($segment, $position));
            if ($source === null) {
                continue;
            }
            $key = strtolower($source['table'] . '|' . $source['qualifier']);
            $sources[$key] = $source;
        }
        return $sources;
    }

    private function directProjection(string $field, int $fromPosition): ?array
    {
        $selectEnd = stripos($this->analysisBody, 'SELECT') + strlen('SELECT');
        $projection = substr($this->analysisBody, $selectEnd, $fromPosition - $selectEnd);
        $starts = [0];
        self::scan($projection, function (string $type, int $position) use (&$starts): void {
            if ($type === 'comma') {
                $starts[] = $position + 1;
            }
        });
        $ends = array_map(fn (int $start): int => $start - 1, array_slice($starts, 1));
        $ends[] = strlen($projection);
        $identifier = '(?:[A-Za-z_][A-Za-z0-9_]*|\[[A-Za-z_][A-Za-z0-9_]*\])';

        foreach ($starts as $index => $start) {
            $item = trim(substr($projection, $start, $ends[$index] - $start));
            if ($index === 0) {
                $item = preg_replace('/^(?:DISTINCT\s+)?(?:TOP\s*(?:\(\s*\d+\s*\)|\d+)\s+)?/i', '', $item);
            }
            if (preg_match(
                '/^((?:' . $identifier . '\s*\.\s*)?' . $identifier . ')'
                    . '(?:\s+(?:AS\s+)?(' . $identifier . '))?$/i',
                $item,
                $matches
            ) !== 1) {
                continue;
            }
            $parts = preg_split('/\s*\.\s*/', $matches[1]);
            $parts = array_map(fn (string $part): string => trim($part, '[]'), $parts ?: []);
            $column = end($parts);
            $alias = isset($matches[2]) ? trim($matches[2], '[]') : $column;
            if (!is_string($column) || strcasecmp($alias, $field) !== 0) {
                continue;
            }
            return [
                'column' => $column,
                'qualifiers' => count($parts) === 2 ? [strtolower($parts[0])] : [],
            ];
        }

        return null;
    }

    public function injectMappedFilters(?string $whereCondition, ?string $havingCondition): string
    {
        if ($whereCondition === null && $havingCondition === null) {
            return $this->body;
        }

        $tokens = self::topLevelTokens($this->body);
        foreach ($tokens as $token) {
            if (in_array($token['value'], ['UNION', 'INTERSECT', 'EXCEPT'], true)) {
                throw new ApiRequestException(
                    'Runtime filter placement is ambiguous for this SQL resource.',
                    'INVALID_SQL_RUNTIME_FILTER',
                    [['path' => 'filters', 'message' => 'Use an output filter or a dedicated resource for set-operation branches.']]
                );
            }
        }

        $insertions = [];
        if ($whereCondition !== null) {
            $boundary = $this->firstTokenPosition($tokens, ['GROUP', 'HAVING', 'ORDER', 'OFFSET', 'FETCH', 'FOR']);
            $where = $this->firstTokenPosition($tokens, ['WHERE']);
            array_push($insertions, ...$this->clauseInsertions(
                'WHERE', $where, $boundary ?? strlen($this->body), $whereCondition, 0
            ));
        }
        if ($havingCondition !== null) {
            $boundary = $this->firstTokenPosition($tokens, ['ORDER', 'OFFSET', 'FETCH', 'FOR']);
            $having = $this->firstTokenPosition($tokens, ['HAVING']);
            array_push($insertions, ...$this->clauseInsertions(
                'HAVING', $having, $boundary ?? strlen($this->body), $havingCondition, 1
            ));
        }

        usort($insertions, function (array $left, array $right): int {
            $positionOrder = $right['position'] <=> $left['position'];
            return $positionOrder !== 0
                ? $positionOrder
                : $right['priority'] <=> $left['priority'];
        });
        $body = $this->body;
        foreach ($insertions as $insertion) {
            $body = substr($body, 0, $insertion['position'])
                . $insertion['sql']
                . substr($body, $insertion['position']);
        }
        return $body;
    }

    /**
     * Text that adds `$condition` to the clause `$keyword` (at `$existing`,
     * when the statement has one before `$position`) or appends the clause at
     * `$position`. An authored condition is parenthesized, so its own OR
     * cannot capture the runtime condition: `a OR b` becomes
     * `(a OR b) AND (condition)`. Text inserted at `$position` starts on a new
     * line, so an authored trailing `--` comment cannot swallow it.
     */
    private function clauseInsertions(string $keyword, ?int $existing, int $position, string $condition, int $priority): array
    {
        if ($existing === null || $existing >= $position) {
            return [['position' => $position, 'priority' => $priority, 'sql' => "\n{$keyword} {$condition} "]];
        }
        return [
            ['position' => $existing + strlen($keyword), 'priority' => $priority, 'sql' => ' ('],
            ['position' => $position, 'priority' => $priority, 'sql' => "\n) AND ({$condition}) "],
        ];
    }

    private function firstTokenPosition(array $tokens, array $values): ?int
    {
        foreach ($tokens as $index => $token) {
            if (in_array($token['value'], $values, true)) {
                if ($token['value'] === 'FOR'
                    && !in_array($tokens[$index + 1]['value'] ?? null, ['JSON', 'XML', 'BROWSE'], true)) {
                    continue;
                }
                return $token['start'];
            }
        }
        return null;
    }

    private function parseSource(string $sql): ?array
    {
        $identifier = '(?:[A-Za-z_][A-Za-z0-9_]*|\[[A-Za-z_][A-Za-z0-9_]*\]|\{\{database:[a-z][a-z0-9_-]{0,63}\}\})';
        if (preg_match(
            '/^\s*((?:' . $identifier . '\s*\.\s*){0,3}' . $identifier . ')'
                . '(?:\s+(?:AS\s+)?(' . $identifier . '))?/i',
            $sql,
            $matches
        ) !== 1) {
            return null;
        }

        $parts = preg_split('/\s*\.\s*/', $matches[1]);
        // A placeholder part becomes its logical database id.
        $parts = array_map(fn (string $part): string => preg_match(self::DATABASE_PLACEHOLDER, $part, $placeholder) === 1
            ? $placeholder[1] : trim($part, '[]'), $parts ?: []);
        $table = end($parts);
        $alias = isset($matches[2]) ? trim($matches[2], '[]') : null;
        if ($alias !== null && in_array(strtoupper($alias), [
            'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'JOIN', 'ON',
            'WHERE', 'GROUP', 'HAVING', 'ORDER', 'OFFSET', 'FETCH', 'FOR',
        ], true)) {
            $alias = null;
        }
        if (!is_string($table)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1
            || ($alias !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1)) {
            return null;
        }

        // Keep every written part: a database- or server-qualified source must
        // never be mistaken for a table of the connected database.
        $count = count($parts);
        return [
            'table' => $table,
            'schema' => $parts[$count - 2] ?? null,
            'database' => $parts[$count - 3] ?? null,
            'server' => $parts[$count - 4] ?? null,
            'qualifier' => $alias ?? $table,
        ];
    }

    private static function topLevelTokens(string $sql): array
    {
        $tokens = [];
        self::scan($sql, function (string $type, int $start, string $value) use (&$tokens): void {
            if ($type === 'word') {
                $tokens[] = ['value' => strtoupper($value), 'start' => $start];
            }
        });
        return $tokens;
    }

    private static function hasTopLevelStatementSeparator(string $sql): bool
    {
        $found = false;
        self::scan($sql, function (string $type) use (&$found): void {
            if ($type === 'semicolon') {
                $found = true;
            }
        });
        return $found;
    }

    /** Visit top-level tokens, or with $allDepths words inside parentheses too. */
    private static function scan(string $sql, callable $visitor, bool $allDepths = false): void
    {
        $length = strlen($sql);
        $depth = 0;
        for ($index = 0; $index < $length;) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($character === "'") {
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== "'") continue;
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $index++;
                        continue;
                    }
                    $index++;
                    break;
                }
                continue;
            }
            if ($character === '"' || $character === '[') {
                $closing = $character === '[' ? ']' : '"';
                $start = $index;
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== $closing) continue;
                    if ($index + 1 < $length && $sql[$index + 1] === $closing) {
                        $index++;
                        continue;
                    }
                    $index++;
                    break;
                }
                if ($depth === 0 || $allDepths) $visitor('quoted', $start, substr($sql, $start, $index - $start));
                continue;
            }
            if ($character === '{' && $next === '{') {
                // One opaque token, so a placeholder's id is never read as SQL.
                $end = strpos($sql, '}}', $index + 2);
                $start = $index;
                $index = $end === false ? $length : $end + 2;
                if ($depth === 0 || $allDepths) $visitor('placeholder', $start, substr($sql, $start, $index - $start));
                continue;
            }
            if ($character === '.') {
                if ($depth === 0 || $allDepths) $visitor('dot', $index, '.');
                $index++;
                continue;
            }
            if ($character === '-' && $next === '-') {
                $newline = strpos($sql, "\n", $index + 2);
                $index = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;
                continue;
            }
            if ($character === '(') {
                if ($depth === 0) $visitor('open', $index, '(');
                $depth++;
                $index++;
                continue;
            }
            if ($character === ')') {
                if ($depth === 1) $visitor('close', $index, ')');
                $depth = max(0, $depth - 1);
                $index++;
                continue;
            }
            if ($depth === 0 && $character === ';') {
                $visitor('semicolon', $index, ';');
                $index++;
                continue;
            }
            if ($depth === 0 && $character === ',') {
                $visitor('comma', $index, ',');
                $index++;
                continue;
            }
            if (($depth === 0 || $allDepths) && preg_match('/[A-Za-z_]/', $character) === 1) {
                $start = $index;
                while ($index < $length && preg_match('/[A-Za-z0-9_]/', $sql[$index]) === 1) {
                    $index++;
                }
                $visitor('word', $start, substr($sql, $start, $index - $start));
                continue;
            }
            $index++;
        }
    }
}
