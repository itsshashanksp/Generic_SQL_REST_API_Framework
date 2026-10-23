-- CTE, window functions, date aggregation and a derived table.
WITH MonthlySales AS (
    SELECT
        DATEFROMPARTS(YEAR(OrderDate), MONTH(OrderDate), 1) AS SalesMonth,
        CustomerId,
        SUM(Amount) AS SalesAmount
    FROM dbo.DemoOrders
    GROUP BY DATEFROMPARTS(YEAR(OrderDate), MONTH(OrderDate), 1), CustomerId
), Ranked AS (
    SELECT
        SalesMonth,
        CustomerId,
        SalesAmount,
        ROW_NUMBER() OVER (PARTITION BY SalesMonth ORDER BY SalesAmount DESC) AS SalesRank
    FROM MonthlySales
)
SELECT SalesMonth, CustomerId, SalesAmount, SalesRank
FROM Ranked
WHERE SalesRank <= 10
