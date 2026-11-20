export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

/** A generation as the server presents it. Money is an exact decimal dollar string. */
export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    width: number;
    height: number;
    quality: string;
    reference_image_count: number;
    estimated_charge_min: string;
    estimated_charge_max: string;
    charge: string | null;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
};

export type SizeOption = {
    value: string;
    label: string;
    width: number;
    height: number;
};

export type QualityOption = {
    value: string;
    label: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        per_page: number;
        links: PaginationLink[];
    };
};
