// Authored callback store only; it is not a native event or ABI test.
#pragma once
#include <functional>
#include <vector>
#include <stdexcept>
namespace callback {
inline bool& test_add_failure(){static bool v=false;return v;}
inline std::function<void()>& test_add_hook(){static std::function<void()> v;return v;}
}
namespace event {
template<typename F>struct Store {
 std::vector<F> values;
 void Add(F f){values.push_back(f);if(callback::test_add_hook())callback::test_add_hook()();if(callback::test_add_failure())throw std::runtime_error("authored store failure");}
 void Fire(int a,int b,int c){for(const auto& f:values)f(a,b,c);}
};
struct PaymentStatusChanged:Store<std::function<void(int,int,int)>>{};
}
namespace callback {template<typename T>T& Store(){static T* s=new T;return *s;}}
