-- ============================================================
-- 002_catalogo_demo.sql
-- Catálogo e estoque de demonstração, com os mesmos ids do seed da Fase 3 (o catálogo saiu do
-- banco do serviço de OS para este; docs/fase-4/CONTRATOS.md §7, no oficina-api).
-- O sufixo _demo faz o runner pular este arquivo em produção. Idempotente: INSERT IGNORE.
-- ============================================================

INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('c597dc97-5a51-497c-acdb-3ae16aab815e', 'Troca de oleo e filtro', 180.00, 40);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('d21e4c6f-ff5d-49f6-b0b0-2594967327ea', 'Alinhamento e balanceamento', 150.00, 60);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('40a16623-56b7-e455-8127-070a24404173', 'Revisao de freios completa', 420.00, 120);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('45626b4d-eee4-80ec-3188-c49524742602', 'Troca de correia dentada', 890.00, 240);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('b04a81bb-4c93-06b1-0173-a6c8779506ed', 'Diagnostico eletronico (scanner)', 120.00, 45);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('c4699fb1-9980-976d-fac6-0988b349024c', 'Higienizacao do ar-condicionado', 260.00, 90);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('51695b26-88ea-f06a-ce5f-b4b8d1653898', 'Troca de embreagem', 1650.00, 360);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('34af09c9-1a21-ab76-84e9-2d06dd22d07f', 'Revisao de suspensao dianteira', 720.00, 180);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('5b7ae86c-fa16-8a5d-bf33-6e096d38ef5b', 'Troca de bateria', 90.00, 20);
INSERT IGNORE INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES ('ff495ea1-93c9-32d5-025f-05c1c919ae3d', 'Reparo do sistema de arrefecimento', 540.00, 150);

INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('22912477-063f-4c06-431c-dcbb7fec1ade', 'Filtro de oleo - motor 1.0/1.6', 38.90, 120);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('bec1745b-41a1-f81d-8436-201cc0408ffb', 'Oleo sintetico 5W30 (litro)', 54.50, 300);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('b4e35f65-d29c-5474-b009-21263ff5c64f', 'Pastilha de freio dianteira (par)', 189.00, 60);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('39e177a3-dd47-b8cb-d2a0-8e8b93cff256', 'Disco de freio ventilado (unidade)', 245.00, 40);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('31ca5039-660f-3450-1583-46ae2bda6c74', 'Correia dentada com tensor', 410.00, 25);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('bce74781-780d-102b-a9a5-72489642f6c2', 'Bateria 60Ah', 489.00, 18);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('616adf64-2936-0186-4a76-31c61c3f1a7f', 'Filtro de ar do motor', 62.00, 90);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('ae8b02ad-3e71-dd05-81b8-35941f6e8e35', 'Filtro de cabine (antipolen)', 71.50, 80);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('721ac1b3-b869-1666-5bbc-99234220881b', 'Kit de embreagem completo', 1280.00, 8);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('66ce289c-beb9-dfda-e561-9aa39a718e3b', 'Amortecedor dianteiro (unidade)', 335.00, 30);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('cb6ae131-8da1-f708-70ff-0ce7904ccc0b', 'Vela de ignicao (unidade)', 29.90, 200);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('0fece609-c624-c68f-94bd-cf554e69d9c4', 'Radiador de agua', 620.00, 10);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('ee47f599-e9d5-ca36-72e8-256a5f8d953f', 'Fluido de freio DOT4 (500ml)', 34.00, 75);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('45d5e736-9b13-0c6c-7848-a0a15099212d', 'Aditivo de radiador (litro)', 27.50, 110);
INSERT IGNORE INTO parts_inventory (id, description, price, stock_quantity) VALUES ('95d1ae71-6f83-9346-d838-f1caf7acbef9', 'Palheta limpador para-brisa (par)', 89.00, 55);
