CXX ?= g++
CXXFLAGS ?= -std=c++17 -O2 -Wall -Wextra
OCR_LIBS ?= -ltesseract -llept

.PHONY: build test run clean

build: ocr_engine

ocr_engine: main.cpp
	$(CXX) $(CXXFLAGS) -o $@ $< $(OCR_LIBS)

test: build
	php -l index.php
	php -l process.php
	php -l load_dic.php
	php -l algorithms.php
	./ocr_engine >/dev/null 2>&1; test $$? -eq 1

run: build
	php -S 127.0.0.1:8080 index.php

clean:
	rm -f ocr_engine
