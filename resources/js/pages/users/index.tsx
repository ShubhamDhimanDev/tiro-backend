import { Form, Head } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BreadcrumbItem } from '@/types';

/**
 * Confirmed against `App\Http\Controllers\Admin\UserController`:
 *   GET  /users          -> this page, with a `staff` prop (array)
 *   POST /users          -> create a staff account + assign a role, then
 *                           redirect back with a `flash.toast` success
 *                           message (picked up by the global toaster).
 * The 6 role values match §3 of docs/architecture/07-admin-auth-permissions.md.
 */
const ROLE_OPTIONS = [
    { value: 'super_admin', label: 'Super Admin' },
    { value: 'ecommerce', label: 'Ecommerce' },
    { value: 'operations', label: 'Operations' },
    { value: 'customer_support', label: 'Customer Support' },
    { value: 'technician', label: 'Technician' },
    { value: 'fleet', label: 'Fleet' },
] as const;

type StaffMember = {
    id: number;
    name: string;
    email: string;
    roles: string[];
};

export default function UsersIndex({ staff = [] }: { staff?: StaffMember[] }) {
    return (
        <>
            <Head title="Roles & Users" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Roles & Users"
                    description="Provision staff accounts and assign a role. New hires get an email to set their own password — nobody sets a password for them here."
                />

                <Can
                    permission="roles-users.manage"
                    fallback={
                        <p className="text-muted-foreground text-sm">
                            You don't have permission to manage staff accounts.
                        </p>
                    }
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>Invite staff member</CardTitle>
                            <CardDescription>
                                Creates the account and sends a
                                set-your-password invite email. This never sets
                                a password directly.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...UserController.store.form()}
                                resetOnSuccess
                                className="grid gap-6 sm:grid-cols-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                                autoComplete="name"
                                                placeholder="Full name"
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="email">
                                                Email address
                                            </Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                name="email"
                                                required
                                                autoComplete="email"
                                                placeholder="name@example.com"
                                            />
                                            <InputError
                                                message={errors.email}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="role">Role</Label>
                                            <Select name="role" required>
                                                <SelectTrigger
                                                    id="role"
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Select a role" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {ROLE_OPTIONS.map(
                                                        (role) => (
                                                            <SelectItem
                                                                key={role.value}
                                                                value={
                                                                    role.value
                                                                }
                                                            >
                                                                {role.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.role} />
                                        </div>

                                        <div className="flex items-end sm:col-span-2">
                                            <Button disabled={processing}>
                                                Send invite
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Staff</CardTitle>
                            <CardDescription>
                                Everyone with panel access today.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {staff.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No staff accounts to show yet.
                                </p>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="py-2 font-medium">
                                                Name
                                            </th>
                                            <th className="py-2 font-medium">
                                                Email
                                            </th>
                                            <th className="py-2 font-medium">
                                                Role(s)
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {staff.map((member) => (
                                            <tr
                                                key={member.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2">
                                                    {member.name}
                                                </td>
                                                <td className="text-muted-foreground py-2">
                                                    {member.email}
                                                </td>
                                                <td className="py-2">
                                                    <div className="flex flex-wrap gap-1">
                                                        {member.roles.map(
                                                            (role) => (
                                                                <Badge
                                                                    key={role}
                                                                    variant="outline"
                                                                >
                                                                    {role}
                                                                </Badge>
                                                            ),
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </Can>
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Roles & Users',
            href: UserController.index().url,
        },
    ] satisfies BreadcrumbItem[],
};
