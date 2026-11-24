import { useForm } from '@inertiajs/react';
import { Head, Link } from '@inertiajs/react';
import type { FormEvent } from 'react';
import BrandController from '@/actions/App/Http/Controllers/Admin/Products/BrandController';
import CatalogImportController from '@/actions/App/Http/Controllers/Admin/Products/CatalogImportController';
import ProductExportController from '@/actions/App/Http/Controllers/Admin/Products/ProductExportController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BreadcrumbItem } from '@/types';
import type {
    CatalogImportResult,
    CatalogImportRowNote,
} from '@/types/catalog';

type ImportFormat = 'woocommerce' | 'simple';

type ImportFormData = {
    file: File | null;
    dry_run: boolean;
    format: ImportFormat;
};

function RowNoteTable({ notes }: { notes: CatalogImportRowNote[] }) {
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="border-b text-left">
                    <th className="py-2 font-medium">Row</th>
                    <th className="py-2 font-medium">Message</th>
                </tr>
            </thead>
            <tbody>
                {notes.map((note, index) => (
                    <tr
                        key={`${note.row}-${index}`}
                        className="border-b last:border-0"
                    >
                        <td className="py-2 align-top font-medium">
                            {note.row}
                        </td>
                        <td className="text-muted-foreground py-2">
                            {note.message}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function ImportResultCard({ result }: { result: CatalogImportResult }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {result.dryRun
                        ? 'Dry-run result — nothing was saved'
                        : 'Import result'}
                </CardTitle>
                <CardDescription>
                    {result.rowsProcessed} row(s) processed —{' '}
                    {result.brandsCreated} brand(s) created,{' '}
                    {result.brandsMatched} matched, {result.modelsCreated}{' '}
                    model(s) created, {result.modelsUpdated} updated,{' '}
                    {result.variantsCreated} variant(s) created,{' '}
                    {result.variantsUpdated} updated.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                {result.errors.length === 0 &&
                result.warnings.length === 0 &&
                result.skipped.length === 0 ? (
                    <Badge>All rows imported cleanly</Badge>
                ) : (
                    <>
                        {result.errors.length > 0 && (
                            <div className="space-y-3">
                                <Badge variant="destructive">
                                    {result.errors.length} row(s) had errors —
                                    those rows were not saved, every other row
                                    still was
                                </Badge>
                                <RowNoteTable notes={result.errors} />
                            </div>
                        )}

                        {result.warnings.length > 0 && (
                            <div className="space-y-3">
                                <Badge variant="secondary">
                                    {result.warnings.length} row(s) imported
                                    with a warning — a value was defaulted or
                                    assumed
                                </Badge>
                                <RowNoteTable notes={result.warnings} />
                            </div>
                        )}

                        {result.skipped.length > 0 && (
                            <div className="space-y-3">
                                <Badge variant="outline">
                                    {result.skipped.length} row(s) skipped —
                                    deliberately excluded (unpublished or
                                    duplicate export rows), not an error
                                </Badge>
                                <RowNoteTable notes={result.skipped} />
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

export default function CatalogImportIndex({
    result,
}: {
    result?: CatalogImportResult;
}) {
    const form = useForm<ImportFormData>({
        file: null,
        dry_run: false,
        format: 'woocommerce',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();

        form.post(CatalogImportController.store().url, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Catalog import" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Bulk catalog import</CardTitle>
                        <CardDescription>
                            Upload a CSV (or JSON) file to create/update brands,
                            tyre models, and their size variants in bulk. Rows
                            that already exist are updated; nothing is deleted.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="format">File format</Label>
                                <Select
                                    value={form.data.format}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'format',
                                            v as ImportFormat,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="format"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="woocommerce">
                                            WooCommerce product export
                                        </SelectItem>
                                        <SelectItem value="simple">
                                            Simple price list (one row per size)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-muted-foreground text-sm">
                                    {form.data.format === 'simple' ? (
                                        <>
                                            Columns:{' '}
                                            <code>
                                                Brand, Model, Category, Tyre
                                                type, SKU, Size, Load index,
                                                Speed rating, Base price ($),
                                                Status
                                            </code>
                                            . Size like <code>205/55R16</code>;
                                            price in dollars. Empty rows are
                                            ignored, and EAN/weight already on a
                                            variant are kept. This is the layout
                                            the{' '}
                                            <Link
                                                href={
                                                    ProductExportController.index()
                                                        .url
                                                }
                                                className="underline"
                                            >
                                                Export
                                            </Link>{' '}
                                            tab produces by default, so an
                                            export can be edited and imported
                                            back.
                                        </>
                                    ) : (
                                        <>
                                            A raw WooCommerce export. Rows with{' '}
                                            <code>Published &lt; 1</code> or a
                                            "(Copy)" name suffix are treated as
                                            trash/duplicate data and skipped
                                            automatically.
                                        </>
                                    )}
                                </p>
                                <InputError message={form.errors.format} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="file">CSV or JSON file</Label>
                                <input
                                    id="file"
                                    type="file"
                                    accept=".csv,.txt,.json,text/csv,application/json"
                                    onChange={(e) =>
                                        form.setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                    className="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                />
                                <InputError message={form.errors.file} />
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="dry_run"
                                    checked={form.data.dry_run}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'dry_run',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="dry_run"
                                    className="font-normal"
                                >
                                    Validate only (dry run) — report results
                                    without saving anything
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                disabled={form.processing || !form.data.file}
                            >
                                {form.data.dry_run
                                    ? 'Validate file'
                                    : 'Import file'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {result && <ImportResultCard result={result} />}
            </div>
        </>
    );
}

CatalogImportIndex.layout = {
    breadcrumbs: [
        { title: 'Products', href: BrandController.index().url },
        {
            title: 'Bulk import',
            href: CatalogImportController.index().url,
        },
    ] satisfies BreadcrumbItem[],
};
