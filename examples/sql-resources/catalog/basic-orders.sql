-- Assumes dbo.DemoOrders(OrderId, CustomerId, OrderDate, Amount, Status).
SELECT TOP (100)
    O.OrderId,
    O.CustomerId,
    O.OrderDate,
    O.Amount,
    CASE WHEN O.Status = 'OPEN' THEN 'Open' ELSE 'Closed' END AS StatusLabel
FROM dbo.DemoOrders AS O
WHERE O.Amount > 0
ORDER BY O.OrderDate DESC, O.OrderId ASC
