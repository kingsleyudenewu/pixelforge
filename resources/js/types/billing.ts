/** Pixelforge's price list (GET /v1/pricing), as shared by the server. */
export type Pricing = {
    rates: Record<string, string>;
    characters_per_text_token: number;
    text_token_variance_basis_points: number;
    tokens_per_reference_image: number;
    output_tokens: Record<string, Record<string, number>>;
    reasoning_tokens: Record<string, { min: number; max: number }>;
};

/** The signed-in user's credits. Credits are US dollars, as exact decimal strings. */
export type Credits = {
    balance: string;
    packs: string[];
};
