import { useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import type { FormEvent } from 'react';
import VehicleController from '@/actions/App/Http/Controllers/Admin/Vehicles/VehicleController';
import VehicleFitmentImportController from '@/actions/App/Http/Controllers/Admin/Vehicles/VehicleFitmentImportController';
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
import type { FitmentImportResult } from '@/types/vehicles';

type ImportFormData = {
    file: File | null;
    dry_run: boolean;
};

function ImportResultCard({ result }: { result: FitmentImportResult }) {
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
                    {result.vehiclesCreated} vehicle(s) created,{' '}
                    {result.vehiclesMatched} matched, {result.fitmentsCreated}{' '}
                    fitment row(s) created, {result.fitmentsUpdated} updated.
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
                                    <th className="py-2 font-medium">Row</th>
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

export default function VehicleFitmentImportIndex({
    result,
}: {
    result?: FitmentImportResult;
}) {
    const form = useForm<ImportFormData>({ file: null, dry_run: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();

        form.post(VehicleFitmentImportController.store().url, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Vehicle fitment import" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Bulk fitment import</CardTitle>
                        <CardDescription>
                            Upload a CSV or JSON file to create/update vehicles
                            and their OE fitment rows in bulk. One row per
                            position — a staggered vehicle is two rows sharing
                            the same make/model/series/body type/year range.
                            Columns: make, model, series, body_type, year_from,
                            year_to, position, width, profile, rim_diameter,
                            load_index, speed_rating, is_staggered, source,
                            confidence, notes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
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
                                    Validate only (dry run) — report errors
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

VehicleFitmentImportIndex.layout = {
    breadcrumbs: [
        { title: 'Vehicles', href: VehicleController.index().url },
        {
            title: 'Bulk import',
            href: VehicleFitmentImportController.index().url,
        },
    ] satisfies BreadcrumbItem[],
};
