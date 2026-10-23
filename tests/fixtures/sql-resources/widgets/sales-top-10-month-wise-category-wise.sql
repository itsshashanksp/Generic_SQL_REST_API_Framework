WITH SalesData AS
(
    SELECT
        SUBSTRING(CONVERT(VARCHAR, BIL.Bill_Date), 5, 2) AS Month,
        CAT.Cat_Desc AS Category,

        ROUND(
            SUM(
                CASE
                    WHEN BIL.Status = 'S' THEN BIL.Item_Value
                    ELSE -1 * BIL.Item_Value
                END
            ) / 100000, 2
        ) AS Sales

    FROM BillDetTable BIL, CategoryTable CAT

    WHERE
        BIL.Cat_Code = CAT.Cat_Code

    GROUP BY
        SUBSTRING(CONVERT(VARCHAR, BIL.Bill_Date), 5, 2),
        CAT.Cat_Desc
),
RankedData AS
(
    SELECT
        Month,
        Category,
        Sales,
        DENSE_RANK() OVER
        (
            PARTITION BY Month
            ORDER BY Sales DESC
        ) AS RowNo
    FROM SalesData
)

SELECT
    Month,
    Category,
    Sales
FROM RankedData
WHERE RowNo <= 10
ORDER BY
    Month,
    Sales DESC
