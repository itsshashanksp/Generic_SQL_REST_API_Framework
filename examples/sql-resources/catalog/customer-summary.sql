-- JOIN, aggregation, GROUP BY and HAVING. Runtime source/HAVING filters require trusted execution metadata.
SELECT
    C.CustomerId,
    C.CustomerName,
    COUNT(*) AS OrderCount,
    SUM(O.Amount) AS TotalAmount,
    AVG(O.Amount) AS AverageAmount
FROM dbo.DemoCustomers AS C
INNER JOIN dbo.DemoOrders AS O ON O.CustomerId = C.CustomerId
GROUP BY C.CustomerId, C.CustomerName
HAVING SUM(O.Amount) > 0
