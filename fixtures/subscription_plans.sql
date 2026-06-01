INSERT INTO saas_subscription_plan
    (code, name, description, price_amount, currency, duration_days, is_active, max_addresses, max_qr_codes, max_deliveries_per_month, can_track_delivery, can_use_custom_qr_code, can_create_business_address, created_at, updated_at)
VALUES
    ('FREE', 'Free', 'Plan gratuit pour demarrer.', 0, 'GNF', 30, true, 1, 1, 3, false, false, false, now(), now()),
    ('BASIC', 'Basic', 'Plan standard mensuel.', 50000, 'GNF', 30, true, 5, 5, 20, true, false, false, now(), now()),
    ('PREMIUM', 'Premium', 'Plan premium mensuel.', 100000, 'GNF', 30, true, 20, 20, 100, true, true, false, now(), now()),
    ('BUSINESS', 'Business', 'Plan business mensuel.', 300000, 'GNF', 30, true, NULL, NULL, NULL, true, true, true, now(), now())
ON CONFLICT (code) DO UPDATE
SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    price_amount = EXCLUDED.price_amount,
    currency = EXCLUDED.currency,
    duration_days = EXCLUDED.duration_days,
    is_active = EXCLUDED.is_active,
    max_addresses = EXCLUDED.max_addresses,
    max_qr_codes = EXCLUDED.max_qr_codes,
    max_deliveries_per_month = EXCLUDED.max_deliveries_per_month,
    can_track_delivery = EXCLUDED.can_track_delivery,
    can_use_custom_qr_code = EXCLUDED.can_use_custom_qr_code,
    can_create_business_address = EXCLUDED.can_create_business_address,
    updated_at = now();
