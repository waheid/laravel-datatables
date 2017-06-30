<?php

namespace Yajra\Datatables\Engines;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

/**
 * Class QueryBuilderEngine.
 *
 * @package Yajra\Datatables\Engines
 * @author  Arjay Angeles <aqangeles@gmail.com>
 */
class QueryBuilderEngine extends BaseEngine
{
    /**
     * Builder object.
     *
     * @var \Illuminate\Database\Query\Builder
     */
    protected $query;

    /**
     * Database connection used.
     *
     * @var \Illuminate\Database\Connection
     */
    protected $connection;

    /**
     * @param \Illuminate\Database\Query\Builder $builder
     */
    public function __construct(Builder $builder)
    {
        $this->query      = $builder;
        $this->request    = resolve('datatables.request');
        $this->config     = resolve('datatables.config');
        $this->columns    = $builder->columns;
        $this->connection = $builder->getConnection();
        if ($this->config->isDebugging()) {
            $this->connection->enableQueryLog();
        }
    }

    /**
     * Set auto filter off and run your own filter.
     * Overrides global search.
     *
     * @param callable $callback
     * @param bool     $globalSearch
     * @return $this
     */
    public function filter(callable $callback, $globalSearch = false)
    {
        $this->overrideGlobalSearch($callback, $this->query, $globalSearch);

        return $this;
    }

    /**
     * Organizes works.
     *
     * @param bool $mDataSupport
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function make($mDataSupport = false)
    {
        try {
            $this->totalRecords = $this->totalCount();

            if ($this->totalRecords) {
                $this->filterRecords();
                $this->ordering();
                $this->paginate();
            }

            $data = $this->transform($this->getProcessedData($mDataSupport));

            return $this->render($data);
        } catch (\Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Count total items.
     *
     * @return integer
     */
    public function totalCount()
    {
        return $this->totalRecords ? $this->totalRecords : $this->count();
    }

    /**
     * Counts current query.
     *
     * @return int
     */
    public function count()
    {
        $builder = $this->prepareCountQuery();
        $table   = $this->connection->raw('(' . $builder->toSql() . ') count_row_table');

        return $this->connection->table($table)
                                ->setBindings($builder->getBindings())
                                ->count();
    }

    /**
     * Prepare count query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function prepareCountQuery()
    {
        $builder = clone $this->query;

        if ($this->isComplexQuery($builder)) {
            $row_count = $this->wrap('row_count');
            $builder->select($this->connection->raw("'1' as {$row_count}"));
        }

        return $builder;
    }

    /**
     * Check if builder query uses complex sql.
     *
     * @param \Illuminate\Database\Query\Builder $builder
     * @return bool
     */
    protected function isComplexQuery($builder)
    {
        return !Str::contains(Str::lower($builder->toSql()), ['union', 'having', 'distinct', 'order by', 'group by']);
    }

    /**
     * Wrap column with DB grammar.
     *
     * @param string $column
     * @return string
     */
    protected function wrap($column)
    {
        return $this->connection->getQueryGrammar()->wrap($column);
    }

    /**
     * Perform sorting of columns.
     *
     * @return void
     */
    public function ordering()
    {
        if ($this->orderCallback) {
            call_user_func($this->orderCallback, $this->getBaseQueryBuilder());

            return;
        }

        foreach ($this->request->orderableColumns() as $orderable) {
            $column = $this->getColumnName($orderable['column'], true);

            if ($this->isBlacklisted($column) && !$this->hasCustomOrder($column)) {
                continue;
            }

            if ($this->hasCustomOrder($column)) {
                $this->applyOrderColumn($column, $orderable);
                continue;
            }

            $column = $this->resolveOrderByColumn($column);
            if ($this->nullsLast) {
                $this->getBaseQueryBuilder()->orderByRaw($this->getNullsLastSql($column, $orderable['direction']));
            } else {
                $this->getBaseQueryBuilder()->orderBy($column, $orderable['direction']);
            }
        }
    }

    /**
     * Get the base query builder instance.
     *
     * @param mixed $instance
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getBaseQueryBuilder($instance = null)
    {
        if (!$instance) {
            $instance = $this->query;
        }

        if ($instance instanceof EloquentBuilder) {
            return $instance->getQuery();
        }

        return $instance;
    }

    /**
     * Check if column has custom sort handler.
     *
     * @param string $column
     * @return bool
     */
    protected function hasCustomOrder($column)
    {
        return isset($this->columnDef['order'][$column]);
    }

    /**
     * Apply orderColumn custom query.
     *
     * @param string $column
     * @param array  $orderable
     */
    protected function applyOrderColumn($column, $orderable): void
    {
        $sql      = $this->columnDef['order'][$column]['sql'];
        $sql      = str_replace('$1', $orderable['direction'], $sql);
        $bindings = $this->columnDef['order'][$column]['bindings'];
        $this->query->orderByRaw($sql, $bindings);
    }

    /**
     * Resolve the proper column name be used.
     *
     * @param string $column
     * @return string
     */
    protected function resolveOrderByColumn($column)
    {
        return $column;
    }

    /**
     * Get NULLS LAST SQL.
     *
     * @param  string $column
     * @param  string $direction
     * @return string
     */
    protected function getNullsLastSql($column, $direction)
    {
        $sql = $this->config->get('datatables.nulls_last_sql', '%s %s NULLS LAST');

        return sprintf($sql, $column, $direction);
    }

    /**
     * Perform column search.
     *
     * @return void
     */
    public function columnSearch()
    {
        $columns = $this->request->columns();

        foreach ($columns as $index => $column) {
            if (!$this->request->isColumnSearchable($index)) {
                continue;
            }

            $column = $this->getColumnName($index);

            if ($this->hasCustomFilter($column)) {
                $keyword = $this->getColumnSearchKeyword($index, $raw = true);
                $this->applyFilterColumn($this->query, $column, $keyword);
            } else {
                if (count(explode('.', $column)) > 1) {
                    $eagerLoads     = $this->getEagerLoads();
                    $parts          = explode('.', $column);
                    $relationColumn = array_pop($parts);
                    $relation       = implode('.', $parts);
                    if (in_array($relation, $eagerLoads)) {
                        $column = $this->joinEagerLoadedColumn($relation, $relationColumn);
                    }
                }

                $keyword = $this->getColumnSearchKeyword($index);
                $this->compileColumnSearch($index, $column, $keyword);
            }

            $this->isFilterApplied = true;
        }
    }

    /**
     * Check if column has custom filter handler.
     *
     * @param  string $columnName
     * @return bool
     */
    public function hasCustomFilter($columnName)
    {
        return isset($this->columnDef['filter'][$columnName]);
    }

    /**
     * Get column keyword to use for search.
     *
     * @param int  $i
     * @param bool $raw
     * @return string
     */
    protected function getColumnSearchKeyword($i, $raw = false)
    {
        $keyword = $this->request->columnKeyword($i);
        if ($raw || $this->request->isRegex($i)) {
            return $keyword;
        }

        return $this->setupKeyword($keyword);
    }

    /**
     * Apply filterColumn api search.
     *
     * @param mixed  $query
     * @param string $columnName
     * @param string $keyword
     * @param string $boolean
     */
    protected function applyFilterColumn($query, $columnName, $keyword, $boolean = 'and')
    {
        $callback = $this->columnDef['filter'][$columnName]['method'];
        $builder  = $query->newQuery();
        $callback($builder, $keyword);
        $query->addNestedWhereQuery($builder, $boolean);
    }

    /**
     * Get eager loads keys if eloquent.
     *
     * @return array
     */
    protected function getEagerLoads()
    {
        if ($this->query instanceof EloquentBuilder) {
            return array_keys($this->query->getEagerLoads());
        }

        return [];
    }

    /**
     * Compile queries for column search.
     *
     * @param int    $i
     * @param string $column
     * @param string $keyword
     */
    protected function compileColumnSearch($i, $column, $keyword)
    {
        if ($this->request->isRegex($i)) {
            $column = strstr($column, '(') ? $this->connection->raw($column) : $column;
            $this->regexColumnSearch($column, $keyword);
        } else {
            $this->compileQuerySearch($this->query, $column, $keyword, '');
        }
    }

    /**
     * Compile regex query column search.
     *
     * @param mixed  $column
     * @param string $keyword
     */
    protected function regexColumnSearch($column, $keyword)
    {
        switch ($this->connection->getDriverName()) {
            case 'oracle':
                $sql = !$this->config
                    ->isCaseInsensitive() ? 'REGEXP_LIKE( ' . $column . ' , ? )' : 'REGEXP_LIKE( LOWER(' . $column . ') , ?, \'i\' )';
                break;

            case 'pgsql':
                $sql = !$this->config->isCaseInsensitive() ? $column . ' ~ ?' : $column . ' ~* ? ';
                break;

            default:
                $sql     = !$this->config
                    ->isCaseInsensitive() ? $column . ' REGEXP ?' : 'LOWER(' . $column . ') REGEXP ?';
                $keyword = Str::lower($keyword);
        }

        $this->query->whereRaw($sql, [$keyword]);
    }

    /**
     * Compile query builder where clause depending on configurations.
     *
     * @param mixed  $query
     * @param string $column
     * @param string $keyword
     * @param string $relation
     */
    protected function compileQuerySearch($query, $column, $keyword, $relation = 'or')
    {
        $column = $this->addTablePrefix($query, $column);
        $column = $this->castColumn($column);
        $sql    = $column . ' LIKE ?';

        if ($this->config->isCaseInsensitive()) {
            $sql = 'LOWER(' . $column . ') LIKE ?';
        }

        $query->{$relation . 'WhereRaw'}($sql, [$this->prepareKeyword($keyword)]);
    }

    /**
     * Patch for fix about ambiguous field.
     * Ambiguous field error will appear when query use join table and search with keyword.
     *
     * @param mixed  $query
     * @param string $column
     * @return string
     */
    protected function addTablePrefix($query, $column)
    {
        if (strpos($column, '.') === false) {
            $q = $this->getBaseQueryBuilder($query);
            if (!$q->from instanceof Expression) {
                $column = $q->from . '.' . $column;
            }
        }

        return $this->wrap($column);
    }

    /**
     * Wrap a column and cast based on database driver.
     *
     * @param  string $column
     * @return string
     */
    protected function castColumn($column)
    {
        switch ($this->connection->getDriverName()) {
            case 'pgsql':
                return 'CAST(' . $column . ' as TEXT)';
            case 'firebird':
                return 'CAST(' . $column . ' as VARCHAR(255))';
            default:
                return $column;
        }
    }

    /**
     * Prepare search keyword based on configurations.
     *
     * @param string $keyword
     * @return string
     */
    protected function prepareKeyword($keyword)
    {
        if ($this->config->isCaseInsensitive()) {
            $keyword = Str::lower($keyword);
        }

        if ($this->config->isWildcard()) {
            $keyword = $this->wildcardLikeString($keyword);
        }

        if ($this->config->isSmartSearch()) {
            $keyword = "%$keyword%";
        }

        return $keyword;
    }

    /**
     * Perform pagination.
     *
     * @return void
     */
    public function paging()
    {
        $this->query->skip($this->request->input('start'))
                    ->take((int) $this->request->input('length') > 0 ? $this->request->input('length') : 10);
    }

    /**
     * Get paginated results.
     *
     * @return \Illuminate\Support\Collection
     */
    public function results()
    {
        return $this->query->get();
    }

    /**
     * Add column in collection.
     *
     * @param string          $name
     * @param string|callable $content
     * @param bool|int        $order
     * @return \Yajra\Datatables\Engines\BaseEngine|\Yajra\Datatables\Engines\QueryBuilderEngine
     */
    public function addColumn($name, $content, $order = false)
    {
        $this->pushToBlacklist($name);

        return parent::addColumn($name, $content, $order);
    }

    /**
     * Get query builder instance.
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Perform global search for the given keyword.
     *
     * @param string $keyword
     */
    protected function globalSearch($keyword)
    {
        $this->query->where(function ($query) use ($keyword) {
            $query = $this->getBaseQueryBuilder($query);

            collect($this->request->searchableColumnIndex())
                ->map(function ($index) {
                    return $this->getColumnName($index);
                })
                ->reject(function ($column) {
                    return $this->isBlacklisted($column) && !$this->hasCustomFilter($column);
                })
                ->each(function ($column) use ($keyword, $query) {
                    if ($this->hasCustomFilter($column)) {
                        $this->applyFilterColumn($query, $column, $keyword, 'or');
                    } else {
                        $this->compileQuerySearch($query, $column, $keyword);
                    }

                    $this->isFilterApplied = true;
                });
        });
    }

    /**
     * Append debug parameters on output.
     *
     * @param  array $output
     * @return array
     */
    protected function showDebugger(array $output)
    {
        $output['queries'] = $this->connection->getQueryLog();
        $output['input']   = $this->request->all();

        return $output;
    }
}
