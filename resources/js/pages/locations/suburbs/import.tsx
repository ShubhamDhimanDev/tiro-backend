import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SuburbController from '@/actions/App/Http/Controllers/Admin/Locations/SuburbController';
import SuburbImportController from '@/actions/App/Http/Controllers/Admin/Locations/SuburbImportController';
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
import type { BreadcrumbItem } from '@/types';
import type { SuburbImportResult } from '@/types/locations';

type ImportFormData = {
    file: File | null;
    dry_run: boolean;
};

function ImportResultCard({ result }: { result: SuburbImportResult }) {
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
                    {result.created} created, {result.updated} updated,{' '}
                    {result.unchanged} unchanged.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {result.errors.length === 0 ? (
                    <Badge>All rows imported cleanly</Badge>
                ) : (
                    <>
                        <Badge variant="destructive">
                            {result.errors.length} row(s) had errors — those
                            rows were not saved, every other row still was
                        </Badge>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2 font-medium">
                                        Sheet row
                                    </th>
                                    <th className="py-2 font-medium">Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                {result.errors.map((error, index) => (
                                    <tr
                                        key={`${error.row}-${index}`}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 align-top font-medium">
                                            {error.row}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {error.message}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

export default function SuburbImportIndex({
    result,
}: {
    result?: SuburbImportResult;
}) {
    const form = useForm<ImportFormData>({ file: null, dry_run: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();

        form.post(SuburbImportController.store().url, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Import suburbs" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Import suburbs from Excel</CardTitle>
                        <CardDescription>
                            Upload an .xlsx or .csv file with the columns{' '}
                            <strong>Suburb, Postcode, State, Country</strong> in
                            the first row. State can be the full name (Victoria)
                            or the code (VIC). Optional Latitude and Longitude
                            columns are applied when present. A suburb is
                            matched on name + postcode + state: existing ones
                            are updated, new ones are created. Blank coordinate
                            cells never erase saved coordinates.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="file">Excel or CSV file</Label>
                                <input
                                    id="file"
                                    type="file"
                                    accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
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
                                    Validate only (dry run) — report what would
                                    change without saving anything
                                </Label>
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing || !form.data.file
                                    }
                                >
                                    {form.processing
                                        ? 'Importing…'
                                        : form.data.dry_run
                                          ? 'Validate file'
                                          : 'Import file'}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={SuburbController.index().url}>
                                        Back to suburbs
                                    </Link>
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {result && <ImportResultCard result={result} />}
            </div>
        </>
    );
}

SuburbImportIndex.layout = {
    breadcrumbs: [
        { title: 'Locations', href: SuburbController.index().url },
        { title: 'Suburbs', href: SuburbController.index().url },
        { title: 'Import', href: SuburbImportController.index().url },
    ] satisfies BreadcrumbItem[],
};
