/** Mirrors `App\Enums\ContentPageType`. */
export type ContentPageType =
    | 'page'
    | 'blog_post'
    | 'guide'
    | 'location_page'
    | 'promo_landing';

/** Mirrors `App\Enums\PageStatus` — shared publish-lifecycle vocabulary for `ContentPage` and `Faq`, distinct from `Status` (types/catalog.ts). */
export type PageStatus = 'draft' | 'published' | 'archived';

export type ContentPageSummary = {
    id: number;
    title: string;
    type: ContentPageType;
};

export type Faq = {
    id: number;
    question: string;
    answer: string;
    category: string | null;
    content_page_id: number | null;
    content_page?: ContentPageSummary | null;
    sort_order: number;
    status: PageStatus;
    created_at: string;
    updated_at: string;
};

export type ContentPage = {
    id: number;
    type: ContentPageType;
    title: string;
    slug: string;
    excerpt: string | null;
    body: string;
    featured_image_path: string | null;
    meta_title: string | null;
    meta_description: string | null;
    og_image_path: string | null;
    category: string | null;
    status: PageStatus;
    published_at: string | null;
    service_zone_id: number | null;
    promotion_id: number | null;
    service_zone?: { id: number; name: string } | null;
    promotion?: { id: number; name: string } | null;
    faqs?: Faq[];
    sort_order: number;
    created_at: string;
    updated_at: string;
};
