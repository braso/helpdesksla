-- Migration 004: Extended fields for users and organizations
-- Run once — does NOT use IF NOT EXISTS (MySQL 8.0 limitation on ADD COLUMN)

ALTER TABLE users
  ADD COLUMN phone_mobile VARCHAR(30)  NULL AFTER phone,
  ADD COLUMN position     VARCHAR(100) NULL AFTER phone_mobile,
  ADD COLUMN department   VARCHAR(100) NULL AFTER position;

ALTER TABLE organizations
  ADD COLUMN trade_name           VARCHAR(200) NULL AFTER name,
  ADD COLUMN cnpj                 VARCHAR(20)  NULL AFTER trade_name,
  ADD COLUMN ie                   VARCHAR(30)  NULL AFTER cnpj,
  ADD COLUMN im                   VARCHAR(30)  NULL AFTER ie,
  ADD COLUMN phone                VARCHAR(30)  NULL AFTER im,
  ADD COLUMN email                VARCHAR(200) NULL AFTER phone,
  ADD COLUMN website              VARCHAR(200) NULL AFTER email,
  ADD COLUMN address_zip          VARCHAR(10)  NULL AFTER website,
  ADD COLUMN address_street       VARCHAR(255) NULL AFTER address_zip,
  ADD COLUMN address_number       VARCHAR(20)  NULL AFTER address_street,
  ADD COLUMN address_complement   VARCHAR(100) NULL AFTER address_number,
  ADD COLUMN address_neighborhood VARCHAR(100) NULL AFTER address_complement,
  ADD COLUMN address_city         VARCHAR(100) NULL AFTER address_neighborhood,
  ADD COLUMN address_state        CHAR(2)      NULL AFTER address_city,
  ADD COLUMN address_country      VARCHAR(60)  NULL AFTER address_state;
