import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export default function DeleteConfirmDialog({
    name,
    label,
    onConfirm,
    trigger,
    description,
}: {
    name: string;
    label: string;
    onConfirm: () => void;
    trigger?: React.ReactNode;
    description?: string;
}) {
    const [confirmation, setConfirmation] = useState('');
    const [open, setOpen] = useState(false);

    const isMatch = confirmation === name;

    return (
        <Dialog
            open={open}
            onOpenChange={(v) => {
                setOpen(v);
                if (!v) setConfirmation('');
            }}
        >
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button variant="destructive">Delete {label}</Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {name}</DialogTitle>
                    <DialogDescription>
                        {description ??
                            `This will permanently delete the ${label} and all of its resources. This action cannot be undone.`}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        Type{' '}
                        <span className="font-semibold text-foreground">
                            {name}
                        </span>{' '}
                        to confirm.
                    </p>
                    <Input
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                        placeholder={name}
                        autoFocus
                    />
                </div>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={!isMatch}
                        onClick={() => {
                            onConfirm();
                            setOpen(false);
                        }}
                    >
                        Delete {label}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
