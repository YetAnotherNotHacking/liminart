#!/usr/bin/env python3
"""
Simple test script to verify the Pixel Canvas API endpoints
"""

import requests
import json
import time

BASE_URL = "http://localhost:9696"

def test_health():
    """Test health endpoint"""
    print("Testing health endpoint...")
    response = requests.get(f"{BASE_URL}/health")
    print(f"Health: {response.status_code} - {response.json()}")
    return response.status_code == 200

def test_ip():
    """Test IP endpoint"""
    print("Testing IP endpoint...")
    response = requests.get(f"{BASE_URL}/api/ip")
    print(f"IP: {response.status_code} - {response.json()}")
    return response.status_code == 200

def test_state():
    """Test canvas state endpoint"""
    print("Testing canvas state endpoint...")
    response = requests.get(f"{BASE_URL}/api/state?info=true")
    print(f"State: {response.status_code} - {response.json()}")
    return response.status_code == 200

def test_raw_pixel():
    """Test raw pixel placement (for bots)"""
    print("Testing raw pixel placement...")
    pixel_data = {
        "x": 100,
        "y": 100,
        "r": 255,
        "g": 0,
        "b": 0
    }
    response = requests.post(f"{BASE_URL}/api/pixel/raw", json=pixel_data)
    print(f"Raw Pixel: {response.status_code} - {response.json()}")
    return response.status_code == 200

def test_stats():
    """Test stats endpoint"""
    print("Testing stats endpoint...")
    response = requests.get(f"{BASE_URL}/api/stats")
    print(f"Stats: {response.status_code} - {response.json()}")
    return response.status_code == 200

def test_monitor():
    """Test monitor endpoint"""
    print("Testing monitor endpoint...")
    response = requests.get(f"{BASE_URL}/api/monitor")
    print(f"Monitor: {response.status_code} - {response.json()}")
    return response.status_code == 200

def test_canvas_export():
    """Test canvas export"""
    print("Testing canvas export...")
    response = requests.get(f"{BASE_URL}/api/canvas?format=json")
    print(f"Canvas Export: {response.status_code} - {len(response.json().get('pixels', []))} pixels")
    return response.status_code == 200

def main():
    """Run all tests"""
    print("=" * 50)
    print("Pixel Canvas API Test Suite")
    print("=" * 50)
    
    tests = [
        ("Health Check", test_health),
        ("IP Endpoint", test_ip),
        ("Canvas State", test_state),
        ("Raw Pixel Placement", test_raw_pixel),
        ("Statistics", test_stats),
        ("Monitor", test_monitor),
        ("Canvas Export", test_canvas_export),
    ]
    
    passed = 0
    total = len(tests)
    
    for test_name, test_func in tests:
        print(f"\n--- {test_name} ---")
        try:
            if test_func():
                print(f"✅ {test_name} PASSED")
                passed += 1
            else:
                print(f"❌ {test_name} FAILED")
        except Exception as e:
            print(f"❌ {test_name} ERROR: {e}")
    
    print("\n" + "=" * 50)
    print(f"Test Results: {passed}/{total} tests passed")
    print("=" * 50)
    
    if passed == total:
        print("🎉 All tests passed! The API is working correctly.")
    else:
        print("⚠️  Some tests failed. Check the output above for details.")

if __name__ == "__main__":
    main() 