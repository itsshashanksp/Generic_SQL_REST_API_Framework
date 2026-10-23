-- UNION ALL and a correlated EXISTS subquery.
SELECT O.OrderId, 'current' AS SourceName
FROM dbo.DemoOrders AS O
WHERE EXISTS (
    SELECT 1 FROM dbo.DemoCustomers AS C
    WHERE C.CustomerId = O.CustomerId AND C.IsActive = 1
)
UNION ALL
SELECT A.OrderId, 'archive' AS SourceName
FROM dbo.DemoOrderArchive AS A
