import type { ImgHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * TMT brand logo. The artwork has black lettering on a transparent
 * background, so it always sits on a white plate to stay legible in dark mode.
 */
export default function AppLogoIcon({
    className,
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            {...props}
            src="/tmt-logo.png"
            alt="TMT"
            className={cn(
                'rounded-sm bg-white object-contain p-0.5',
                className,
            )}
        />
    );
}
