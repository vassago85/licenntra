import path from 'node:path';

export type Role = 'owner' | 'operations' | 'finance' | 'dealerAdmin' | 'dealerUser';

export interface DemoUser {
    role: Role;
    name: string;
    email: string;
}

/** Personas created by DemoSeeder; every password is "password". */
export const users: Record<Role, DemoUser> = {
    owner: { role: 'owner', name: 'Owner', email: 'owner@licentra.test' },
    operations: { role: 'operations', name: 'Operations', email: 'reviewer@licentra.test' },
    finance: { role: 'finance', name: 'Finance', email: 'finance@licentra.test' },
    dealerAdmin: { role: 'dealerAdmin', name: 'Thandi Mokoena', email: 'thandi.mokoena@highveld.test' },
    dealerUser: { role: 'dealerUser', name: 'Johan Botha', email: 'johan.botha@highveld.test' },
};

export const password = 'password';

export function storageStatePath(role: Role): string {
    return path.resolve(import.meta.dirname, '..', '.auth', `${role}.json`);
}
