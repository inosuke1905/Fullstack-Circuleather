USE circuleather;

-- Order editing updates retained lines and removes lines taken out of an order.
GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.order_items TO 'student'@'%';

-- Removing an order also removes its lines through ON DELETE CASCADE.
GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.orders TO 'student'@'%';
