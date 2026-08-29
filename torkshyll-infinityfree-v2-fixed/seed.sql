USE `torkshyll`;
INSERT INTO settings (id, business_name, address, phone, receipt_header, receipt_footer)
VALUES (1, 'Torks & Hyll', 'Accra, Ghana', '', 'Good shops run on good rhythm.', 'Thank you for shopping with Torks & Hyll.')
ON DUPLICATE KEY UPDATE business_name = VALUES(business_name);

INSERT INTO users (employee_id, first_name, last_name, email, password_hash, role)
VALUES
('MGR-001', 'Store', 'Manager', 'manager@torkshyll.local', '$2y$12$a7dDYx1WHoD29BIRylZvZOlnJPwr6zMLXm0tAiyo7RsLuu95Jaq/i', 'manager'),
('CSH-001', 'Front', 'Cashier', 'cashier@torkshyll.local', '$2y$12$a7dDYx1WHoD29BIRylZvZOlnJPwr6zMLXm0tAiyo7RsLuu95Jaq/i', 'cashier')
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name);

INSERT INTO categories (name) VALUES ('General'), ('Beverages'), ('Snacks'), ('Household')
ON DUPLICATE KEY UPDATE name = VALUES(name);