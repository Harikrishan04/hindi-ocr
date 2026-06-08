#include <iostream>
#include <string>
#include <vector>
#include <iomanip>
#include <cctype>
#include <filesystem>
#include <tesseract/baseapi.h>
#include <leptonica/allheaders.h>

using namespace std;
namespace fs = std::filesystem;

//  Count UTF-8 Characters ---
int countUtf8Chars(const string& text) {
    int count = 0;
    for (unsigned char c : text) {
        // In UTF-8, bytes starting with 10xxxxxx are continuation bytes.
        if ((c & 0xC0) != 0x80) {
            // Check for spaces/newlines to ignore them in char count 
            if (!isspace(c)) {
                count++;
            }
        }
    }
    return count;
}

//  Count Words ---
int countWords(const string& text) {
    int count = 0;
    bool inWord = false;
    for (unsigned char c : text) {
        if (isspace(c)) {
            inWord = false;
        } else if (!inWord) {
            inWord = true;
            count++;
        }
    }
    return count;
}

//  Count Hindi Sentences ---
// (Looks for |, ?, !)
int countSentences(const string& text) {
    int count = 0;
    for (size_t i = 0; i < text.length(); i++) {
        unsigned char c = static_cast<unsigned char>(text[i]);
        // Check for '?' or '!'
        if (c == '?' || c == '!') {
            count++;
        }
        // Check for Hindi Danda '।'of 3 bytes (UTF-8 Hex: E0 A5 A4)
        else if (c == 0xE0 && i + 2 < text.length()) {
            if (static_cast<unsigned char>(text[i+1]) == 0xA5
                && static_cast<unsigned char>(text[i+2]) == 0xA4) {
                count++;
                i += 2; // Skip the next 2 bytes of this char 
            }
        }
    }
    return (count == 0 && countWords(text) > 0) ? 1 : count;
}

bool processImage(const string& imagePath, const string& tessdataPath) {
    tesseract::TessBaseAPI *api = new tesseract::TessBaseAPI();
    
    // Initialize for Hindi 
    if (api->Init(tessdataPath.c_str(), "hin")) {
        cerr << "Error: Could not initialize Tesseract (hin)." << endl;
        delete api;
        return false;
    }

    Pix *image = pixRead(imagePath.c_str());
    if (!image) {
        cerr << "Error: Could not read image file." << endl;
        api->End();
        delete api;
        return false;
    }

    api->SetImage(image);
    char* outText = api->GetUTF8Text();
    string text = outText ? string(outText) : "";

    // 1. Output Raw Text for PHP to capture
    cout << text; 

    
    int words = countWords(text);
    int sentences = countSentences(text);
    int characters = countUtf8Chars(text); 
    double avgLen = (words > 0) ? (double)characters / words : 0.0;

    // 3. Output Stats Delimiter
    // Use a unique clear marker that won't appear in normal text
    cout << "\n\n#####STATS#####" << endl;
    cout << "W:" << words << endl;
    cout << "S:" << sentences << endl;
    cout << "C:" << characters << endl;
    cout << fixed << setprecision(2) << "A:" << avgLen << endl;

    // Cleanup 
    api->End();
    pixDestroy(&image);
    delete[] outText;
    delete api;
    return true;
}

int main(int argc, char* argv[]) {
    if (argc < 3 || string(argv[1]) != "ocr") {
        cout << "Usage: ./ocr_engine ocr <image_path>" << endl;
        return 1;
    }
    fs::path exePath = fs::absolute(argv[0]).parent_path();
    string tessdataPath = (exePath / "tesseract" / "tessdata").string();
    return processImage(argv[2], tessdataPath) ? 0 : 2;
}
