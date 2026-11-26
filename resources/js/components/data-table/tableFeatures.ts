import {
    createColumnHelper,
    createSortedRowModel,
    rowSortingFeature,
    sortFn_alphanumeric,
    sortFn_basic,
    sortFn_datetime,
    sortFn_text,
    tableFeatures,
    type ColumnDef,
    type RowData,
} from '@tanstack/vue-table';

export interface DataTableColumnMeta {
    align?: 'left' | 'right';
    numeric?: boolean;
    headerClass?: string;
    cellClass?: string;
}

export const dataTableFeatures = tableFeatures({
    rowSortingFeature,
    sortedRowModel: createSortedRowModel(),
    sortFns: {
        alphanumeric: sortFn_alphanumeric,
        basic: sortFn_basic,
        datetime: sortFn_datetime,
        text: sortFn_text,
    },
    columnMeta: {} as DataTableColumnMeta,
});

export type DataTableFeatures = typeof dataTableFeatures;

export type DataTableColumnDef<TData extends RowData, TValue = unknown> = ColumnDef<
    DataTableFeatures,
    TData,
    TValue
>;

export function createDataTableColumnHelper<TData extends RowData>() {
    return createColumnHelper<DataTableFeatures, TData>();
}
