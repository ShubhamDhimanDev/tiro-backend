import { useForm } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import TechnicianController from '@/actions/App/Http/Controllers/Admin/Bookings/TechnicianController';
import TechnicianLoginController from '@/actions/App/Http/Controllers/Admin/Bookings/TechnicianLoginController';
import { BookingsSubNav } from '@/components/bookings-sub-nav';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    STATUS_OPTIONS,
    TECHNICIAN_EMPLOYMENT_TYPE_OPTIONS,
    statusBadgeVariant,
} from '@/lib/enums';
import type { Technician, TechnicianEmploymentType } from '@/types/bookings';
import type { Status } from '@/types/catalog';
import type { BreadcrumbItem } from '@/types';

type TechnicianFormData = {
    name: string;
    employment_type: TechnicianEmploymentType;
    certifications: string;
    status: Status;
};

function TechnicianFormDialog({ technician }: { technician?: Technician }) {
    const isEdit = !!technician;
    const [open, setOpen] = useState(false);

    const form = useForm<TechnicianFormData>({
        name: technician?.name ?? '',
        employment_type: technician?.employment_type ?? 'employee',
        certifications: (technician?.certifications ?? []).join(', '),
        status: technician?.status ?? 'active',
    });

    const submit = () => {
        const payload = {
            ...form.data,
            certifications: form.data.certifications
                .split(',')
                .map((c) => c.trim())
                .filter((c) => c.length > 0),
        };

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!isEdit) {
                    form.reset();
                }
            },
        };

        form.transform(() => payload);

        if (isEdit) {
            form.put(TechnicianController.update(technician.id).url, options);
        } else {
            form.post(TechnicianController.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={isEdit ? 'outline' : 'default'}
                    size={isEdit ? 'sm' : 'default'}
                >
                    {isEdit ? 'Edit' : 'New technician'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${technician.name}` : 'New technician'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="employment_type">
                                Employment type
                            </Label>
                            <Select
                                value={form.data.employment_type}
                                onValueChange={(v) =>
                                    form.setData(
                                        'employment_type',
                                        v as TechnicianEmploymentType,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="employment_type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TECHNICIAN_EMPLOYMENT_TYPE_OPTIONS.map(
                                        (o) => (
                                            <SelectItem
                                                key={o.value}
                                                value={o.value}
                                            >
                                                {o.label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.employment_type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="status">Status</Label>
                            <Select
                                value={form.data.status}
                                onValueChange={(v) =>
                                    form.setData('status', v as Status)
                                }
                            >
                                <SelectTrigger id="status" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {STATUS_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="certifications">Certifications</Label>
                        <Input
                            id="certifications"
                            placeholder="Comma-separated, e.g. Wheel alignment, EV servicing"
                            value={form.data.certifications}
                            onChange={(e) =>
                                form.setData('certifications', e.target.value)
                            }
                        />
                        <InputError message={form.errors.certifications} />
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        {isEdit ? 'Save changes' : 'Create technician'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The explicit "create login" step — see
 * docs/architecture/07-admin-auth-permissions.md §5. Only rendered (by the
 * parent) when `technician.user_id` is null; gated behind
 * `roles-users.manage`, not `bookings.manage` — see
 * `App\Http\Requests\Admin\Bookings\CreateTechnicianLoginRequest`'s docblock
 * for why.
 */
function CreateLoginDialog({ technician }: { technician: Technician }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ email: string }>({ email: '' });

    const submit = () => {
        form.post(TechnicianLoginController.store(technician.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="secondary" size="sm">
                    Create login
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Create a login for {technician.name}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4">
                    <p className="text-muted-foreground text-sm">
                        Creates a staff account with the Technician role and
                        emails a set-your-password invite — this never sets a
                        password directly.
                    </p>
                    <div className="grid gap-2">
                        <Label htmlFor="login-email">Email address</Label>
                        <Input
                            id="login-email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        <InputError message={form.errors.email} />
                    </div>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={submit}>
                        Send invite
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function TechniciansIndex({
    technicians,
}: {
    technicians: Technician[];
}) {
    return (
        <>
            <Head title="Technicians" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Technicians"
                    description="The capacity unit the booking engine schedules against, via their shifts."
                />

                <BookingsSubNav active="technicians" />

                <div className="flex justify-end">
                    <Can permission="bookings.manage">
                        <TechnicianFormDialog />
                    </Can>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Technicians</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {technicians.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No technicians yet.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-2 font-medium">
                                            Name
                                        </th>
                                        <th className="py-2 font-medium">
                                            Employment
                                        </th>
                                        <th className="py-2 font-medium">
                                            Certifications
                                        </th>
                                        <th className="py-2 font-medium">
                                            Login
                                        </th>
                                        <th className="py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {technicians.map((technician) => (
                                        <tr
                                            key={technician.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2 font-medium">
                                                {technician.name}
                                            </td>
                                            <td className="text-muted-foreground py-2 capitalize">
                                                {technician.employment_type}
                                            </td>
                                            <td className="py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    {(
                                                        technician.certifications ??
                                                        []
                                                    ).map((cert) => (
                                                        <Badge
                                                            key={cert}
                                                            variant="outline"
                                                        >
                                                            {cert}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="py-2">
                                                {technician.user ? (
                                                    <Badge variant="default">
                                                        {technician.user.email}
                                                    </Badge>
                                                ) : (
                                                    <div className="flex items-center gap-2">
                                                        <Badge variant="outline">
                                                            No login
                                                        </Badge>
                                                        <Can permission="roles-users.manage">
                                                            <CreateLoginDialog
                                                                technician={
                                                                    technician
                                                                }
                                                            />
                                                        </Can>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        technician.status,
                                                    )}
                                                >
                                                    {technician.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-right">
                                                <Can permission="bookings.manage">
                                                    <TechnicianFormDialog
                                                        technician={technician}
                                                    />
                                                </Can>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

TechniciansIndex.layout = {
    breadcrumbs: [
        { title: 'Bookings', href: TechnicianController.index().url },
        { title: 'Technicians', href: TechnicianController.index().url },
    ] satisfies BreadcrumbItem[],
};
