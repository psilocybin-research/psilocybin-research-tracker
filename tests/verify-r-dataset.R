#!/usr/bin/env Rscript
# Independent base-R interoperability check; expected CSVs come from SQLite.
args <- commandArgs(trailingOnly = TRUE)
stopifnot(length(args) == 3L)
dataset <- readRDS(args[[1L]])
stopifnot(identical(names(dataset), c("publications", "metadata")))
for (table in names(dataset)) {
  expected <- read.csv(file.path(args[[2L]], paste0(table, ".csv")),
                       fileEncoding = "UTF-8", colClasses = "character",
                       na.strings = args[[3L]], stringsAsFactors = FALSE,
                       check.names = FALSE, strip.white = FALSE,
                       blank.lines.skip = FALSE, comment.char = "")
  types <- readLines(file.path(args[[2L]], paste0(table, ".types")), warn = FALSE)
  stopifnot(length(types) == ncol(expected))
  for (column in seq_along(types)) {
    if (types[[column]] == "integer") expected[[column]] <- as.integer(expected[[column]])
    else if (types[[column]] == "numeric") expected[[column]] <- as.numeric(expected[[column]])
    else stopifnot(types[[column]] == "character")
  }
  stopifnot(is.data.frame(dataset[[table]]), identical(dataset[[table]], expected))
}
cat("Real R readRDS: all public table values, column types, UTF-8 and NA match SQLite.\n")
